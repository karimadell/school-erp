<?php

namespace App\Filament\Resources\ClassResource\Pages;

use App\Filament\Resources\ClassResource;
use Filament\Resources\Pages\Page;
use Filament\Notifications\Notification;

use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\Day;
use App\Models\Period;
use App\Models\Subject;
use App\Services\CurriculumAwareTimetableConflictChecker;
use App\Services\TimetableGenerationService;
use App\Services\TimetableLessonService;
use App\Support\CurriculumContext;
use App\Support\TimetableSlot;

class TimetableGrid extends Page
{
    protected static string $resource = ClassResource::class;

    protected string $view = 'filament.resources.class-resource.pages.timetable-grid';

    public $days;
    public $periods;
    public $subjects;
    public $classId;

    public $selectedSubject = [];
    public $selectedTeacher = [];

    public $dragLessonId = null;

    /**
     * Batch 4 / Timetable Permissions (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
     * defense-in-depth only — Filament consults a resource sub-page's own
     * canAccess() solely for navigation-item visibility, not for actually
     * gating the route. Real enforcement is the abort_unless() at the top
     * of mount() below; this override must not be relied on by itself.
     */
    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']) ?? false;
    }

    public function mount($record): void
    {
        abort_unless(auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']), 403);

        $this->classId = $record;

        $this->days = Day::orderBy('order')->get();
        $this->periods = Period::orderBy('number')->get();

        $this->subjects = $this->curriculumSubjectsForClass();
    }

    /**
     * Batch 1 / Curriculum Enforcement (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
     * only subjects in the active year's Curriculum for this class's
     * grade are ever offered in the manual-entry subject dropdown — an
     * invalid choice is never shown, not accepted and rejected after
     * the fact. CurriculumSubjectRule (used by saveLesson()/moveLesson()
     * via CurriculumAwareTimetableConflictChecker) is the backstop for
     * any write that doesn't go through this dropdown.
     */
    public function curriculumSubjectsForClass()
    {
        $subjectIds = CurriculumContext::forClass($this->classId)?->subjectIds() ?? collect();

        return Subject::with('teachers')
            ->where('is_active', true)
            ->whereIn('id', $subjectIds)
            ->get();
    }

    /**
     * Batch 2 / TeacherAssignment Enforcement (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
     * only teachers actually assigned (TeacherAssignment: teacher x this
     * class x the given subject x active year) are ever offered in the
     * manual-entry teacher dropdown — replaces the previous
     * teacher_subject-qualification-only source. TeacherAssignmentRule
     * (via CurriculumAwareTimetableConflictChecker) is the backstop for
     * any write that doesn't go through this dropdown.
     */
    public function assignedTeachersFor($subjectId)
    {
        if (!$subjectId) {
            return collect();
        }

        return Teacher::whereHas('currentAssignments', function ($q) use ($subjectId) {
            $q->where('class_id', $this->classId)
              ->where('subject_id', $subjectId);
        })->get();
    }

    public function getLesson($dayId, $periodId)
    {
        return Timetable::with(['subject','teacher'])
            ->where('class_id',$this->classId)
            ->where('day_id',$dayId)
            ->where('period_id',$periodId)
            ->first();
    }

    public function saveLesson($dayId,$periodId)
    {
        abort_unless(auth()->user()?->can('manage timetable'), 403);

        $subjectId = $this->selectedSubject[$dayId][$periodId] ?? null;
        $teacherId = $this->selectedTeacher[$dayId][$periodId] ?? null;

        if(!$subjectId || !$teacherId){
            return;
        }

        $conflictKey = app(TimetableLessonService::class)->save(
            $this->classId, $dayId, $periodId, $subjectId, $teacherId,
        );

        if ($conflictKey) {

            Notification::make()
                ->title(__('timetable.validation_error'))
                ->body(__($conflictKey))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('timetable.saved_success'))
            ->success()
            ->send();
    }

    public function startDrag($lessonId): void
    {
        $this->dragLessonId = $lessonId;
    }

    public function moveLesson($targetDayId,$targetPeriodId): void
    {
        abort_unless(auth()->user()?->can('manage timetable'), 403);

        if(!$this->dragLessonId){
            return;
        }

        $draggedLesson = Timetable::find($this->dragLessonId);

        if(!$draggedLesson){
            $this->dragLessonId = null;
            return;
        }

        $sourceDayId = $draggedLesson->day_id;
        $sourcePeriodId = $draggedLesson->period_id;
        $classId = $draggedLesson->class_id;

        $targetLesson = Timetable::where('class_id',$classId)
            ->where('day_id',$targetDayId)
            ->where('period_id',$targetPeriodId)
            ->where('id','!=',$draggedLesson->id)
            ->first();

        // Both the dragged row and (if present) the row being swapped
        // away are excluded — a swap is not a conflict with either of
        // the two rows actually involved in it.
        $ignoreIds = $targetLesson
            ? [$draggedLesson->id, $targetLesson->id]
            : [$draggedLesson->id];

        $slot = new TimetableSlot(
            classId: $classId,
            dayId: $targetDayId,
            periodId: $targetPeriodId,
            teacherId: $draggedLesson->teacher_id,
            subjectId: $draggedLesson->subject_id,
            room: $draggedLesson->room,
            ignoreIds: $ignoreIds,
        );

        $result = app(CurriculumAwareTimetableConflictChecker::class)->check($slot);

        if($result->hasConflict()){

            Notification::make()
                ->title(__('timetable.validation_error'))
                ->body(__($result->first()))
                ->danger()
                ->send();

            $this->dragLessonId = null;
            return;
        }

        if(!$targetLesson){

            $draggedLesson->update([
                'day_id'=>$targetDayId,
                'period_id'=>$targetPeriodId
            ]);

            $this->dragLessonId = null;
            return;
        }

        // Swap: capture the target lesson's data and delete it before
        // moving the dragged lesson in. Updating both rows in place (old
        // behavior) could transiently violate the (teacher_id, day_id,
        // period_id) / (class_id, day_id, period_id) unique constraints
        // whenever the two lessons share a teacher or class — each row
        // briefly needs to sit in the other's still-occupied slot.
        // Deleting the target first means no two rows ever collide.
        $targetData = [
            'class_id' => $targetLesson->class_id,
            'subject_id' => $targetLesson->subject_id,
            'teacher_id' => $targetLesson->teacher_id,
        ];
        $targetLesson->delete();

        $draggedLesson->update([
            'day_id'=>$targetDayId,
            'period_id'=>$targetPeriodId
        ]);

        Timetable::create(array_merge($targetData, [
            'day_id' => $sourceDayId,
            'period_id' => $sourcePeriodId,
        ]));

        $this->dragLessonId = null;
    }

    public function generateTimetable(): void
    {
        abort_unless(auth()->user()?->can('manage timetable'), 403);

        $failureKey = app(TimetableGenerationService::class)->generate(
            $this->classId, collect($this->days), collect($this->periods),
        );

        if ($failureKey) {
            $this->notifyGenerationFailure($failureKey);

            return;
        }

        Notification::make()
            ->title(__('timetable.generated_success'))
            ->success()
            ->send();
    }

    protected function notifyGenerationFailure(string $translationKey): void
    {
        Notification::make()
            ->title(__($translationKey))
            ->danger()
            ->send();
    }
}

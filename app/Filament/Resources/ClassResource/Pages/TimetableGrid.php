<?php

namespace App\Filament\Resources\ClassResource\Pages;

use App\Filament\Resources\ClassResource;
use Filament\Resources\Pages\Page;
use Filament\Notifications\Notification;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\Day;
use App\Models\Period;
use App\Models\Subject;
use App\Models\SchoolClass;
use App\Services\CurriculumAwareTimetableConflictChecker;
use App\Support\CurriculumContext;
use App\Support\TimetableSlot;
use App\Support\WorkingDays;

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
        $this->periods = Period::orderBy('order')->get();

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

        // The row already occupying this exact class+day+period (if any)
        // is the one about to be upserted — excluded from every rule so
        // editing a slot never conflicts with itself.
        $existingId = Timetable::where('class_id',$this->classId)
            ->where('day_id',$dayId)
            ->where('period_id',$periodId)
            ->value('id');

        $slot = new TimetableSlot(
            classId: $this->classId,
            dayId: $dayId,
            periodId: $periodId,
            teacherId: $teacherId,
            subjectId: $subjectId,
            ignoreIds: $existingId ? [$existingId] : [],
        );

        $result = app(CurriculumAwareTimetableConflictChecker::class)->check($slot);

        if($result->hasConflict()){

            Notification::make()
                ->title(__('timetable.validation_error'))
                ->body(__($result->first()))
                ->danger()
                ->send();

            return;
        }

        Timetable::updateOrCreate(
            [
                'class_id'=>$this->classId,
                'day_id'=>$dayId,
                'period_id'=>$periodId
            ],
            [
                'subject_id'=>$subjectId,
                'teacher_id'=>$teacherId
            ]
        );

        Notification::make()
            ->title('Lesson Saved')
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

        $activeYearId = AcademicYear::where('is_active', true)->value('id');

        $classes = SchoolClass::all();

        $workingDays = app(WorkingDays::class)->workingDays($this->days);

        foreach($classes as $class){

            // حذف الجدول القديم
            Timetable::where('class_id',$class->id)->delete();

            $curricula = Curriculum::with('subject')
                ->where('academic_year_id', $activeYearId)
                ->where('grade_id', $class->grade_id)
                ->get();

            $pool = [];

            foreach($curricula as $curriculum){

                $subject = $curriculum->subject;
                $hours = (int)$curriculum->weekly_hours;

                for($i=0;$i<$hours;$i++){
                    $pool[] = $subject;
                }
            }

            shuffle($pool);

            $lastSubjectId = null;

            foreach($workingDays as $day){

                $usedToday = [];

                foreach($this->periods as $period){

                    if(empty($pool)){
                        continue;
                    }

                    $availableSubjects = collect($pool)
                        ->where('id','!=',$lastSubjectId)
                        ->whereNotIn('id',$usedToday);

                    if($availableSubjects->isEmpty()){
                        $availableSubjects = collect($pool);
                    }

                    $subject = $availableSubjects->random();

                    $teacher = Teacher::whereHas('currentAssignments', function($q) use($class,$subject){

                            $q->where('class_id',$class->id)
                              ->where('subject_id',$subject->id);

                        })
                        ->whereDoesntHave('timetables',function($q) use($day,$period){

                            $q->where('day_id',$day->id)
                              ->where('period_id',$period->id);

                        })
                        ->inRandomOrder()
                        ->first();

                    if(!$teacher){
                        continue;
                    }

                    Timetable::create([
                        'class_id'=>$class->id,
                        'day_id'=>$day->id,
                        'period_id'=>$period->id,
                        'subject_id'=>$subject->id,
                        'teacher_id'=>$teacher->id
                    ]);

                    $lastSubjectId = $subject->id;
                    $usedToday[] = $subject->id;

                    foreach($pool as $index=>$poolSubject){

                        if($poolSubject->id === $subject->id){

                            unset($pool[$index]);
                            $pool = array_values($pool);
                            break;

                        }
                    }
                }
            }
        }

        Notification::make()
            ->title('Smart Timetable Generated')
            ->success()
            ->send();
    }
}
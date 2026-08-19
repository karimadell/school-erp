<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Day;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Services\TimetableGenerationService;
use App\Services\TimetableLessonService;
use App\Support\CurriculumContext;
use App\Support\WorkingDays;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Dashboard-native counterpart to Filament's ClassResource\Pages\TimetableGrid
 * (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md). Renders inside the classic
 * dashboard shell instead of the /admin panel so "Расписание" no longer
 * leaves /dashboard. Reuses the same Timetable model and the same
 * TimetableLessonService / TimetableGenerationService the Filament grid
 * delegates to — no business rule is re-implemented here. Drag-and-drop
 * reordering is intentionally not offered in this first cut; manual save
 * and generation cover normal timetable operation.
 */
class ClassTimetableController extends Controller
{
    public function __construct(private readonly WorkingDays $workingDays)
    {
        $this->middleware(function (Request $request, $next) {
            abort_unless(auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']), 403);

            return $next($request);
        });
    }

    public function show(SchoolClass $class): View
    {
        // Display must use the same working-day source of truth as
        // generation (TimetableGenerationService reads the identical
        // WorkingDays/TimetableSetting configuration) so a non-working day
        // (e.g. Friday/Saturday) never appears as a grid column here even
        // though the underlying Day table still has all 7 rows.
        $days = $this->workingDays->workingDays(Day::orderBy('order')->get())->values();
        $periods = Period::orderBy('number')->get();
        $subjects = $this->curriculumSubjectsForClass($class->id);

        $teachersBySubject = $subjects->mapWithKeys(
            fn (Subject $subject) => [
                $subject->id => $this->assignedTeachersFor($class->id, $subject->id)
                    ->map(fn (Teacher $teacher) => ['id' => $teacher->id, 'name' => $teacher->full_name])
                    ->values(),
            ],
        );

        $lessons = Timetable::with(['subject', 'teacher'])
            ->where('class_id', $class->id)
            ->get()
            ->keyBy(fn (Timetable $lesson) => $lesson->day_id.'-'.$lesson->period_id);

        return view('dashboard.classes.timetable', [
            'class' => $class,
            'days' => $days,
            'periods' => $periods,
            'subjects' => $subjects,
            'teachersBySubject' => $teachersBySubject,
            'lessons' => $lessons,
        ]);
    }

    public function save(Request $request, SchoolClass $class): RedirectResponse
    {
        abort_unless(auth()->user()?->can('manage timetable'), 403);

        $data = $request->validate([
            'day_id' => ['required', 'integer'],
            'period_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'teacher_id' => ['required', 'integer'],
        ]);

        $conflictKey = app(TimetableLessonService::class)->save(
            $class->id, $data['day_id'], $data['period_id'], $data['subject_id'], $data['teacher_id'],
        );

        return $conflictKey
            ? back()->with('error', __($conflictKey))
            : back()->with('success', __('timetable.saved_success'));
    }

    public function generate(SchoolClass $class): RedirectResponse
    {
        abort_unless(auth()->user()?->can('manage timetable'), 403);

        $days = Day::orderBy('order')->get();
        $periods = Period::orderBy('number')->get();

        $failureKey = app(TimetableGenerationService::class)->generate($class->id, $days, $periods);

        return $failureKey
            ? back()->with('error', __($failureKey))
            : back()->with('success', __('timetable.generated_success'));
    }

    private function curriculumSubjectsForClass(int $classId)
    {
        $subjectIds = CurriculumContext::forClass($classId)?->subjectIds() ?? collect();

        return Subject::with('teachers')
            ->where('is_active', true)
            ->whereIn('id', $subjectIds)
            ->get();
    }

    private function assignedTeachersFor(int $classId, int $subjectId)
    {
        return Teacher::whereHas('currentAssignments', function ($q) use ($classId, $subjectId) {
            $q->where('class_id', $classId)
                ->where('subject_id', $subjectId);
        })->get();
    }
}

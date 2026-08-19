<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Day;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Services\TimetableGenerationService;
use App\Services\TimetableLessonService;
use App\Support\CurriculumContext;
use App\Support\WorkingDays;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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
        return view('dashboard.classes.timetable', $this->gridData($class));
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

    public function destroy(Request $request, SchoolClass $class): RedirectResponse
    {
        abort_unless(auth()->user()?->can('manage timetable'), 403);

        $data = $request->validate([
            'day_id' => ['required', 'integer'],
            'period_id' => ['required', 'integer'],
        ]);

        app(TimetableLessonService::class)->destroy($class->id, $data['day_id'], $data['period_id']);

        return back()->with('success', __('timetable.deleted_success'));
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

    /**
     * Preview surface for "Предпросмотр PDF" — same grid data and the same
     * dompdf template as the download, just streamed inline instead of
     * forcing a file save.
     */
    public function pdf(SchoolClass $class): Response
    {
        return Pdf::loadView('dashboard.classes.timetable-pdf', $this->exportData($class))
            ->setPaper('a4', 'landscape')
            ->stream($this->pdfFilename($class));
    }

    public function pdfDownload(SchoolClass $class): Response
    {
        return Pdf::loadView('dashboard.classes.timetable-pdf', $this->exportData($class))
            ->setPaper('a4', 'landscape')
            ->download($this->pdfFilename($class));
    }

    /**
     * Dedicated print-friendly view (no dashboard shell, no interactive
     * controls) reusing the exact same grid data as the screen and PDF
     * surfaces so the three never drift apart.
     */
    public function print(SchoolClass $class): View
    {
        return view('dashboard.classes.timetable-print', $this->exportData($class));
    }

    /**
     * Data shared by the interactive grid, the PDF, and the print view.
     * Days/periods/lessons are built once here so working-day filtering
     * (the same WorkingDays source of truth generation itself reads) can
     * never diverge between the three surfaces.
     */
    private function gridData(SchoolClass $class): array
    {
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

        return [
            'class' => $class,
            'days' => $days,
            'periods' => $periods,
            'subjects' => $subjects,
            'teachersBySubject' => $teachersBySubject,
            'lessons' => $lessons,
        ];
    }

    /**
     * Grid data plus the document-identity fields the PDF/print templates
     * need (school name, academic year) but the interactive grid doesn't.
     */
    private function exportData(SchoolClass $class): array
    {
        $data = $this->gridData($class);
        $data['schoolSettings'] = SchoolSetting::find(SchoolSetting::SINGLETON_ID);
        $data['academicYear'] = AcademicYear::where('is_active', true)->first();

        return $data;
    }

    private function pdfFilename(SchoolClass $class): string
    {
        $className = Str::slug($class->name_ru ?? $class->name ?? $class->code ?? 'class', '-');
        $className = $className !== '' ? $className : 'class';

        return "raspisanie-{$className}.pdf";
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

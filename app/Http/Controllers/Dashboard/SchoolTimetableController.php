<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Day;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Timetable;
use App\Support\WorkingDays;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Whole-school timetable view, built on the same canonical Timetable model
 * and WorkingDays source of truth as ClassTimetableController — no second
 * timetable data model. Read-only: editing still happens exclusively on the
 * per-class grid (dashboard.classes.timetable), which this page links to.
 */
class SchoolTimetableController extends Controller
{
    public function __construct(private readonly WorkingDays $workingDays)
    {
        $this->middleware(function (Request $request, $next) {
            abort_unless(auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        if (is_array($request->input('classes'))) {
            $request->merge(['classes' => collect($request->input('classes'))->uniqueStrict()->values()->all()]);
        }

        $validated = $request->validate([
            'classes' => ['nullable', 'array'],
            'classes.*' => [
                'distinct',
                'integer',
                Rule::exists('classes', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
        ]);

        $allClasses = SchoolClass::where('is_active', true)
            ->with('grade')
            ->get()
            ->sortBy(fn (SchoolClass $c) => $c->grade->level ?? 0)
            ->values();

        $selectedIds = collect($validated['classes'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $selectedClasses = $selectedIds->isEmpty()
            ? $allClasses
            : $allClasses->whereIn('id', $selectedIds)->values();

        $days = $this->workingDays->workingDays(Day::orderBy('order')->get())->values();
        $periods = Period::orderBy('number')->get();

        // Conflict detection scans every class's lessons, not just the
        // filtered ones — a teacher double-booked between a selected and
        // an unselected class is still a real conflict.
        $allLessons = Timetable::with(['subject', 'teacher', 'schoolClass'])->get();

        $teacherConflictLessons = $allLessons
            ->groupBy(fn (Timetable $t) => $t->teacher_id.'-'.$t->day_id.'-'.$t->period_id)
            ->filter(fn ($group) => $group->pluck('class_id')->unique()->count() > 1)
            ->flatMap(fn ($group) => $group->pluck('id'))
            ->flip();

        $classConflictLessons = $allLessons
            ->groupBy(fn (Timetable $t) => $t->class_id.'-'.$t->day_id.'-'.$t->period_id)
            ->filter(fn ($group) => $group->count() > 1)
            ->flatMap(fn ($group) => $group->pluck('id'))
            ->flip();

        $duplicateLessons = $allLessons
            ->groupBy(fn (Timetable $t) => implode('-', [
                $t->class_id,
                $t->day_id,
                $t->period_id,
                $t->subject_id,
                $t->teacher_id,
            ]))
            ->filter(fn ($group) => $group->count() > 1)
            ->flatMap(fn ($group) => $group->pluck('id'))
            ->flip();

        $lessonsByClass = $allLessons
            ->whereIn('class_id', $selectedClasses->pluck('id'))
            ->groupBy('class_id')
            ->map(fn ($lessons) => $lessons->groupBy(fn (Timetable $t) => $t->day_id.'-'.$t->period_id));

        return view('dashboard.timetable.index', [
            'allClasses' => $allClasses,
            'selectedClasses' => $selectedClasses,
            'selectedIds' => $selectedIds,
            'days' => $days,
            'periods' => $periods,
            'lessonsByClass' => $lessonsByClass,
            'teacherConflictLessons' => $teacherConflictLessons,
            'classConflictLessons' => $classConflictLessons,
            'duplicateLessons' => $duplicateLessons,
        ]);
    }
}

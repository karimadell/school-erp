<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendar;
use App\Models\AcademicYear;
use App\Models\BellSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Dashboard-native counterpart to
 * App\Filament\Resources\AcademicCalendars\AcademicCalendarResource — same
 * model, same permission gates ('view timetable' / 'manage timetable'),
 * same validation (AcademicCalendar::booted()). No delete route, matching
 * the Filament resource's canDelete() === false.
 */
class AcademicCalendarController extends Controller
{
    public function __construct()
    {
        $this->middleware(function (Request $request, $next) {
            abort_unless(auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']), 403);

            return $next($request);
        });

        $this->middleware('permission:manage timetable')->only(['create', 'store', 'edit', 'update']);
    }

    public function index(): View
    {
        $academicCalendars = AcademicCalendar::with('academicYear')
            ->withCount('events')
            ->orderByDesc('id')
            ->paginate(15);

        return view('dashboard.academic-calendars.index', compact('academicCalendars'));
    }

    public function create(): View
    {
        $academicYears = AcademicYear::where('is_active', true)->orderByDesc('start_date')->get();
        $bellSchedules = BellSchedule::where('is_active', true)
            ->whereIn('academic_year_id', $academicYears->modelKeys())
            ->get();

        return view('dashboard.academic-calendars.create', compact('academicYears', 'bellSchedules'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        AcademicCalendar::create($data);

        return redirect()
            ->route('dashboard.academic-calendars.index')
            ->with('success', __('academic_calendar.created_success'));
    }

    public function edit(AcademicCalendar $academicCalendar): View
    {
        $academicYears = AcademicYear::where('is_active', true)
            ->orWhere('id', $academicCalendar->academic_year_id)
            ->orderByDesc('start_date')
            ->get();

        $bellSchedules = BellSchedule::where('academic_year_id', $academicCalendar->academic_year_id)
            ->where('is_active', true)
            ->get();

        $events = $academicCalendar->events()->orderByDesc('start_date')->get();

        return view('dashboard.academic-calendars.edit', compact('academicCalendar', 'academicYears', 'bellSchedules', 'events'));
    }

    public function update(Request $request, AcademicCalendar $academicCalendar): RedirectResponse
    {
        $data = $this->validated($request, $academicCalendar);

        $academicCalendar->update($data);

        return redirect()
            ->route('dashboard.academic-calendars.edit', $academicCalendar)
            ->with('success', __('academic_calendar.updated_success'));
    }

    /**
     * Mirrors AcademicCalendarForm's `->unique(table: 'academic_calendars',
     * column: 'academic_year_id', ignoreRecord: true)` — the DB has a hard
     * unique constraint on academic_year_id (one calendar per year), so
     * without this the duplicate submission Filament rejects with a normal
     * validation error would 500 here instead (QueryException).
     */
    private function validated(Request $request, ?AcademicCalendar $academicCalendar = null): array
    {
        $data = $request->validate([
            'academic_year_id' => [
                'required', 'integer', 'exists:academic_years,id',
                Rule::unique('academic_calendars', 'academic_year_id')->ignore($academicCalendar?->id),
            ],
            'weekly_days_off' => ['required', 'array', 'min:1'],
            'weekly_days_off.*' => ['string', 'in:' . implode(',', AcademicCalendar::WEEKDAYS)],
            'default_bell_schedule_id' => ['nullable', 'integer', 'exists:bell_schedules,id'],
        ], [
            'academic_year_id.unique' => __('academic_calendar.validation.academic_year_taken'),
        ]);

        return $data;
    }
}

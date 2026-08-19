<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendar;
use App\Models\BellSchedule;
use App\Models\CalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Dashboard-native counterpart to
 * App\Filament\Resources\AcademicCalendars\RelationManagers\EventsRelationManager.
 * Nested under an AcademicCalendar, same permission gates, same validation
 * (CalendarEvent::booted()). No delete route — the relation manager offers
 * no DeleteAction either.
 */
class CalendarEventController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage timetable');
    }

    public function create(AcademicCalendar $academicCalendar): View
    {
        $bellSchedules = BellSchedule::where('academic_year_id', $academicCalendar->academic_year_id)
            ->where('is_active', true)
            ->get();

        return view('dashboard.academic-calendars.events.create', compact('academicCalendar', 'bellSchedules'));
    }

    public function store(Request $request, AcademicCalendar $academicCalendar): RedirectResponse
    {
        $data = $this->validated($request);

        $academicCalendar->events()->create($data);

        return redirect()
            ->route('dashboard.academic-calendars.edit', $academicCalendar)
            ->with('success', __('academic_calendar.events.created_success'));
    }

    public function edit(AcademicCalendar $academicCalendar, CalendarEvent $event): View
    {
        $this->ensureEventBelongsToCalendar($academicCalendar, $event);

        $bellSchedules = BellSchedule::where('academic_year_id', $academicCalendar->academic_year_id)
            ->where('is_active', true)
            ->get();

        return view('dashboard.academic-calendars.events.edit', compact('academicCalendar', 'event', 'bellSchedules'));
    }

    public function update(Request $request, AcademicCalendar $academicCalendar, CalendarEvent $event): RedirectResponse
    {
        $this->ensureEventBelongsToCalendar($academicCalendar, $event);

        $data = $this->validated($request);

        $event->update($data);

        return redirect()
            ->route('dashboard.academic-calendars.edit', $academicCalendar)
            ->with('success', __('academic_calendar.events.updated_success'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:' . implode(',', CalendarEvent::TYPES)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'effect' => ['nullable', 'string', 'in:' . implode(',', CalendarEvent::EFFECTS)],
            'bell_schedule_id' => ['nullable', 'integer', 'exists:bell_schedules,id'],
            'shift' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function ensureEventBelongsToCalendar(AcademicCalendar $academicCalendar, CalendarEvent $event): void
    {
        abort_unless($event->academic_calendar_id === $academicCalendar->id, 404);
    }
}

<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\BellSchedule;
use App\Models\BellSchedulePeriod;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Dashboard-native counterpart to
 * App\Filament\Resources\BellSchedules\RelationManagers\PeriodsRelationManager.
 * Nested under a BellSchedule, same permission gates, same validation
 * (BellSchedulePeriod::booted()). No delete route — the relation manager
 * offers no DeleteAction either.
 */
class BellSchedulePeriodController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage timetable');
    }

    public function create(BellSchedule $bellSchedule): View
    {
        return view('dashboard.bell-schedules.periods.create', compact('bellSchedule'));
    }

    public function store(Request $request, BellSchedule $bellSchedule): RedirectResponse
    {
        $data = $this->validated($request);

        $bellSchedule->periods()->create($data);

        return redirect()
            ->route('dashboard.bell-schedules.edit', $bellSchedule)
            ->with('success', __('bell_schedule.periods.created_success'));
    }

    public function edit(BellSchedule $bellSchedule, BellSchedulePeriod $period): View
    {
        $this->ensurePeriodBelongsToSchedule($bellSchedule, $period);

        return view('dashboard.bell-schedules.periods.edit', compact('bellSchedule', 'period'));
    }

    public function update(Request $request, BellSchedule $bellSchedule, BellSchedulePeriod $period): RedirectResponse
    {
        $this->ensurePeriodBelongsToSchedule($bellSchedule, $period);

        $data = $this->validated($request);

        $period->update($data);

        return redirect()
            ->route('dashboard.bell-schedules.edit', $bellSchedule)
            ->with('success', __('bell_schedule.periods.updated_success'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'period_number' => ['required', 'integer', 'min:1'],
            'label' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'break_after_minutes' => ['required', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function ensurePeriodBelongsToSchedule(BellSchedule $bellSchedule, BellSchedulePeriod $period): void
    {
        abort_unless($period->bell_schedule_id === $bellSchedule->id, 404);
    }
}

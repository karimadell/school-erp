<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\BellSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Dashboard-native counterpart to
 * App\Filament\Resources\BellSchedules\BellScheduleResource — same model,
 * same permission gates ('view timetable' / 'manage timetable'), same
 * validation (enforced in BellSchedule::booted()). No delete route: the
 * Filament resource's canDelete() always returns false, so this mirrors
 * that instead of inventing a destroy action.
 */
class BellScheduleController extends Controller
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
        $bellSchedules = BellSchedule::with('academicYear')
            ->withCount('periods')
            ->orderByDesc('id')
            ->paginate(15);

        return view('dashboard.bell-schedules.index', compact('bellSchedules'));
    }

    public function create(): View
    {
        $academicYears = AcademicYear::where('is_active', true)->orderByDesc('start_date')->get();

        return view('dashboard.bell-schedules.create', compact('academicYears'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        BellSchedule::create($data);

        return redirect()
            ->route('dashboard.bell-schedules.index')
            ->with('success', __('bell_schedule.created_success'));
    }

    public function edit(BellSchedule $bellSchedule): View
    {
        $academicYears = AcademicYear::where('is_active', true)
            ->orWhere('id', $bellSchedule->academic_year_id)
            ->orderByDesc('start_date')
            ->get();

        $periods = $bellSchedule->periods()->get();

        return view('dashboard.bell-schedules.edit', compact('bellSchedule', 'academicYears', 'periods'));
    }

    public function update(Request $request, BellSchedule $bellSchedule): RedirectResponse
    {
        $data = $this->validated($request);

        $bellSchedule->update($data);

        return redirect()
            ->route('dashboard.bell-schedules.edit', $bellSchedule)
            ->with('success', __('bell_schedule.updated_success'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'name' => ['required', 'string', 'max:255'],
            'shift' => ['required', 'integer', 'min:1'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['is_default'] = $request->boolean('is_default');
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}

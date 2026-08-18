<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAcademicYearRequest;
use App\Http\Requests\UpdateAcademicYearRequest;
use App\Models\AcademicYear;
use App\Services\AcademicCoreDeletionGuard;
use Illuminate\Validation\ValidationException;

/**
 * Custom Application Shell Migration — Batch 1. Classic-dashboard
 * counterpart to app/Filament/Resources/AcademicYears/AcademicYearResource.
 * Both share the same AcademicYear model and AcademicYearPolicy — no
 * business logic is duplicated here; the exclusive-active-year rule lives
 * entirely in AcademicYear::save().
 */
class AcademicYearController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(AcademicYear::class, 'academic_year');
    }

    public function index()
    {
        $academicYears = AcademicYear::with(['unlocks' => fn ($query) => $query->where('expires_at', '>', now())])
            ->latest('start_date')->paginate(15);

        return view('dashboard.academic-years.index', compact('academicYears'));
    }

    public function create()
    {
        return view('dashboard.academic-years.create');
    }

    public function store(StoreAcademicYearRequest $request)
    {
        AcademicYear::create($request->validated());

        return redirect()
            ->route('dashboard.academic-years.index')
            ->with('success', __('academic_years.created_success'));
    }

    public function edit(AcademicYear $academicYear)
    {
        return view('dashboard.academic-years.edit', compact('academicYear'));
    }

    public function update(UpdateAcademicYearRequest $request, AcademicYear $academicYear)
    {
        $data = $request->validated();
        $activating = (bool) ($data['is_active'] ?? false);

        if (! $academicYear->is_active && $academicYear->end_date->isPast() && ! $academicYear->isUnlocked()) {
            $detailsChanged = $academicYear->name !== $data['name']
                || $academicYear->start_date->toDateString() !== $data['start_date']
                || $academicYear->end_date->toDateString() !== $data['end_date'];

            if (! $activating || $detailsChanged) {
                throw ValidationException::withMessages([
                    'academic_year_lock' => __('academic_years.locked_activation_only'),
                ]);
            }
        }

        $academicYear->update($data);

        return redirect()
            ->route('dashboard.academic-years.index')
            ->with('success', __('academic_years.updated_success'));
    }

    public function destroy(AcademicYear $academicYear, AcademicCoreDeletionGuard $guard)
    {
        $guard->ensureCanDelete($academicYear);
        $academicYear->delete();

        return redirect()
            ->route('dashboard.academic-years.index')
            ->with('success', __('academic_years.deleted_success'));
    }
}

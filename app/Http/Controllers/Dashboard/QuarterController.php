<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuarterRequest;
use App\Http\Requests\UpdateQuarterRequest;
use App\Models\AcademicYear;
use App\Models\Quarter;
use App\Services\AcademicCoreDeletionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class QuarterController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage academic years');
    }

    public function index(AcademicYear $academicYear): View
    {
        $quarters = $academicYear->quarters()->orderBy('order')->orderBy('start_date')->get();

        return view('dashboard.quarters.index', compact('academicYear', 'quarters'));
    }

    public function create(AcademicYear $academicYear): View
    {
        $this->ensureYearWritable($academicYear);

        return view('dashboard.quarters.create', compact('academicYear'));
    }

    public function store(StoreQuarterRequest $request, AcademicYear $academicYear): RedirectResponse
    {
        $academicYear->quarters()->create($request->validated());

        return redirect()->route('dashboard.academic-years.quarters.index', $academicYear)
            ->with('success', __('quarters.created_success'));
    }

    public function edit(AcademicYear $academicYear, Quarter $quarter): View
    {
        $this->ensureQuarterBelongsToYear($academicYear, $quarter);
        $this->ensureYearWritable($academicYear);

        return view('dashboard.quarters.edit', compact('academicYear', 'quarter'));
    }

    public function update(UpdateQuarterRequest $request, AcademicYear $academicYear, Quarter $quarter): RedirectResponse
    {
        $this->ensureQuarterBelongsToYear($academicYear, $quarter);
        $quarter->update($request->validated());

        return redirect()->route('dashboard.academic-years.quarters.index', $academicYear)
            ->with('success', __('quarters.updated_success'));
    }

    public function destroy(AcademicYear $academicYear, Quarter $quarter, AcademicCoreDeletionGuard $guard): RedirectResponse
    {
        $this->ensureQuarterBelongsToYear($academicYear, $quarter);
        $guard->ensureCanDelete($quarter);
        $quarter->delete();

        return redirect()->route('dashboard.academic-years.quarters.index', $academicYear)
            ->with('success', __('quarters.deleted_success'));
    }

    private function ensureQuarterBelongsToYear(AcademicYear $academicYear, Quarter $quarter): void
    {
        abort_unless($quarter->academic_year_id === $academicYear->id, 404);
    }

    private function ensureYearWritable(AcademicYear $academicYear): void
    {
        abort_unless(
            $academicYear->isWritable(),
            403,
            __('academic_years.locked'),
        );
    }
}

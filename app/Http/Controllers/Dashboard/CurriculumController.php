<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCurriculumRequest;
use App\Http\Requests\UpdateCurriculumRequest;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Grade;
use App\Models\Subject;
use Illuminate\Http\Request;

/**
 * Custom Application Shell Migration — Curriculum Batch 1. Classic-dashboard
 * counterpart to app/Filament/Resources/Curricula/CurriculumResource. Both
 * share the same Curriculum model and CurriculumPolicy — no business logic
 * is duplicated here. The copy-forward-between-years action and the
 * per-student elective enrollment relation manager are Filament-only
 * features, deliberately out of this batch's scope (see the readiness
 * report), and remain reachable at /admin/curricula.
 */
class CurriculumController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Curriculum::class, 'curriculum');
    }

    public function index(Request $request)
    {
        $curricula = Curriculum::with(['academicYear', 'grade', 'subject'])
            ->when($request->filled('academic_year_id'), fn ($query) => $query
                ->where('academic_year_id', $request->academic_year_id))
            ->when($request->filled('grade_id'), fn ($query) => $query
                ->where('grade_id', $request->grade_id))
            ->when($request->filled('type'), fn ($query) => $query
                ->where('type', $request->type))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('dashboard.curricula.index', [
            'curricula' => $curricula,
            'academicYears' => AcademicYear::orderByDesc('start_date')->get(),
            'grades' => Grade::orderBy('id')->get(),
        ]);
    }

    public function create()
    {
        return view('dashboard.curricula.create', $this->formOptions());
    }

    public function store(StoreCurriculumRequest $request)
    {
        Curriculum::create($request->validated());

        return redirect()
            ->route('dashboard.curricula.index')
            ->with('success', __('curriculum.created_success'));
    }

    public function edit(Curriculum $curriculum)
    {
        return view('dashboard.curricula.edit', array_merge(
            ['curriculum' => $curriculum],
            $this->formOptions()
        ));
    }

    public function update(UpdateCurriculumRequest $request, Curriculum $curriculum)
    {
        $curriculum->update($request->validated());

        return redirect()
            ->route('dashboard.curricula.index')
            ->with('success', __('curriculum.updated_success'));
    }

    public function destroy(Curriculum $curriculum)
    {
        $curriculum->delete();

        return redirect()
            ->route('dashboard.curricula.index')
            ->with('success', __('curriculum.deleted_success'));
    }

    private function formOptions(): array
    {
        return [
            'academicYears' => AcademicYear::orderByDesc('start_date')->get(),
            'grades' => Grade::orderBy('id')->get(),
            'subjects' => Subject::orderBy('name_ru')->get(),
        ];
    }
}

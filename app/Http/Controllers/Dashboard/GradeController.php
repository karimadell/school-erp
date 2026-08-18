<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Stage;
use App\Services\AcademicCoreDeletionGuard;
use Illuminate\Http\Request;

class GradeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage grades')->only(['create', 'store', 'edit', 'update', 'destroy']);
    }

    public function index()
    {
        $grades = Grade::with('stage')
            ->orderBy('stage_id')
            ->ordered()
            ->get();

        return view('dashboard.grades.index', compact('grades'));
    }

    public function create(Request $request)
    {
        $selectedStage = $request->has('stage_id')
            ? Stage::findOrFail($request->integer('stage_id'))
            : null;
        $stages = Stage::orderBy('id')->get();
        $returnStage = $this->isScopedReturn($request) ? $selectedStage : null;

        return view('dashboard.grades.create', compact('stages', 'selectedStage', 'returnStage'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'stage_id' => 'required|exists:stages,id',
            'name' => 'required|string|max:255',
        ]);

        $grade = Grade::create([
            'stage_id' => $request->stage_id,
            'name' => $request->name,
        ]);

        return $this->redirectAfterMutation($request, 'dashboard.grades.index', $grade->stage_id)
            ->with('success', __('grades.created_success'));
    }

    public function show(string $id)
    {
        return redirect()->route('dashboard.grades.edit', $id);
    }

    public function edit(Request $request, string $id)
    {
        $grade = Grade::with('stage')->findOrFail($id);
        $stages = Stage::orderBy('id')->get();
        $returnStage = $this->isScopedReturn($request) ? $grade->stage : null;

        return view('dashboard.grades.edit', compact('grade', 'stages', 'returnStage'));
    }

    public function update(Request $request, string $id)
    {
        $grade = Grade::findOrFail($id);

        $request->validate([
            'stage_id' => 'required|exists:stages,id',
            'name' => 'required|string|max:255',
        ]);

        $grade->update([
            'stage_id' => $request->stage_id,
            'name' => $request->name,
        ]);

        $grade->refresh();

        return $this->redirectAfterMutation($request, 'dashboard.grades.index', $grade->stage_id)
            ->with('success', __('grades.updated_success'));
    }

    public function destroy(Request $request, string $id, AcademicCoreDeletionGuard $guard)
    {
        $grade = Grade::findOrFail($id);
        $guard->ensureCanDelete($grade);
        $grade->delete();

        return $this->redirectAfterMutation($request, 'dashboard.grades.index', $grade->stage_id)
            ->with('success', __('grades.deleted_success'));
    }

    private function isScopedReturn(Request $request): bool
    {
        return $request->input('return_to') === 'dashboard.stages.show';
    }

    private function redirectAfterMutation(Request $request, string $fallbackRoute, ?int $defaultStageId = null)
    {
        $returnStage = $this->isScopedReturn($request) && $defaultStageId
            ? Stage::find($defaultStageId)
            : null;

        return $returnStage
            ? redirect()->route('dashboard.stages.show', $returnStage)
            : redirect()->route($fallbackRoute);
    }
}

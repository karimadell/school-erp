<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SchoolClass;
use App\Models\Grade;
use App\Models\Stage;
use App\Services\AcademicCoreDeletionGuard;

class ClassController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage classes')->only(['create', 'store', 'edit', 'update', 'destroy']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Natural academic order: by the grade's canonical numeric level
        // (Grade::scopeOrdered() uses the same field/tiebreak elsewhere —
        // e.g. the create/edit grade dropdowns), not insertion order and
        // not a lexicographic sort on the class code, which would put
        // "10A" before "2A". A plain comparator (rather than
        // Collection::sortBy()'s multi-criteria array form, which expects
        // either a property path or a full ($a, $b) comparator per entry —
        // not a single-value extractor) keeps the null-level-sorts-last
        // tiebreak explicit and easy to verify.
        $classes = SchoolClass::with(['grade.stage'])
            ->get()
            ->sort(function (SchoolClass $a, SchoolClass $b) {
                $levelA = $a->grade?->level ?? PHP_INT_MAX;
                $levelB = $b->grade?->level ?? PHP_INT_MAX;

                return [$levelA, $a->grade_id, $a->code] <=> [$levelB, $b->grade_id, $b->code];
            })
            ->values();

        return view('dashboard.classes.index', compact('classes'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $selectedGrade = $request->has('grade_id')
            ? Grade::with('stage')->findOrFail($request->integer('grade_id'))
            : null;
        $grades = Grade::with('stage')
            ->orderBy('stage_id')
            ->ordered()
            ->get();

        $stages = Stage::orderBy('id')->get();
        $returnStage = $this->isScopedReturn($request) ? $selectedGrade?->stage : null;

        return view('dashboard.classes.create', compact('grades', 'stages', 'selectedGrade', 'returnStage'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'grade_id' => 'required|exists:grades,id',
            'code' => 'required|string|max:50',
            'name_ru' => 'required|string|max:255',
            'capacity' => 'nullable|integer|min:1',
        ]);

        $schoolClass = SchoolClass::create([
            'grade_id' => $request->grade_id,
            'code' => $request->code,
            'name_ar' => $request->name_ru, // auto copy
            'name_ru' => $request->name_ru,
            'capacity' => $request->capacity ?? 25,
            'is_active' => $request->has('is_active'),
        ]);

        $schoolClass->load('grade');

        return $this->redirectAfterMutation($request, 'dashboard.classes.index', $schoolClass->grade?->stage_id)
            ->with('success', __('classes.created_success'));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $class = SchoolClass::with(['grade.stage'])->findOrFail($id);

        return view('dashboard.classes.show', compact('class'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, string $id)
    {
        $class = SchoolClass::with('grade.stage')->findOrFail($id);

        $grades = Grade::with('stage')
            ->orderBy('stage_id')
            ->ordered()
            ->get();

        $stages = Stage::orderBy('id')->get();
        $returnStage = $this->isScopedReturn($request) ? $class->grade?->stage : null;

        return view('dashboard.classes.edit', compact('class', 'grades', 'stages', 'returnStage'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $class = SchoolClass::findOrFail($id);

        $request->validate([
            'grade_id' => 'required|exists:grades,id',
            'code' => 'required|string|max:50',
            'name_ru' => 'required|string|max:255',
            'capacity' => 'nullable|integer|min:1',
        ]);

        $class->update([
            'grade_id' => $request->grade_id,
            'code' => $request->code,
            'name_ar' => $request->name_ru,
            'name_ru' => $request->name_ru,
            'capacity' => $request->capacity ?? 25,
            'is_active' => $request->has('is_active'),
        ]);

        $class->load('grade');

        return $this->redirectAfterMutation($request, 'dashboard.classes.index', $class->grade?->stage_id)
            ->with('success', __('classes.updated_success'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id, AcademicCoreDeletionGuard $guard)
    {
        $class = SchoolClass::with('grade')->findOrFail($id);
        $stageId = $class->grade?->stage_id;
        $guard->ensureCanDelete($class);
        $class->delete();

        return $this->redirectAfterMutation($request, 'dashboard.classes.index', $stageId)
            ->with('success', __('classes.deleted_success'));
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

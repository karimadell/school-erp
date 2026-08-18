<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Services\AcademicCoreDeletionGuard;
use Illuminate\Http\Request;

class StageController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage stages')->only(['create', 'store', 'edit', 'update', 'destroy']);
    }

    public function index()
    {
        $activeAcademicYearId = AcademicYear::query()
            ->where('is_active', true)
            ->value('id');

        $stages = Stage::query()
            ->withCount([
                'grades',
                'classes as school_classes_count',
            ])
            ->when(
                $activeAcademicYearId,
                fn ($query) => $query->selectSub(
                    Enrollment::query()
                        ->selectRaw('COUNT(DISTINCT enrollments.student_id)')
                        ->whereColumn('enrollments.stage_id', 'stages.id')
                        ->where('enrollments.academic_year_id', $activeAcademicYearId)
                        ->where('enrollments.is_active', true)
                        ->where('enrollments.status', 'active'),
                    'current_students_count'
                ),
                fn ($query) => $query->selectRaw('0 as current_students_count')
            )
            ->orderBy('order')
            ->get();

        return view('dashboard.stages.index', compact('stages'));
    }

    public function create()
    {
        return view('dashboard.stages.create');
    }

    public function show(Stage $stage)
    {
        $activeAcademicYear = AcademicYear::query()
            ->where('is_active', true)
            ->first();

        $grades = Grade::query()
            ->select('grades.*')
            ->where('stage_id', $stage->id)
            ->when(
                $activeAcademicYear,
                fn ($query) => $query->selectSub(
                    Enrollment::query()
                        ->selectRaw('COUNT(DISTINCT enrollments.student_id)')
                        ->whereColumn('enrollments.grade_id', 'grades.id')
                        ->where('enrollments.stage_id', $stage->id)
                        ->where('enrollments.academic_year_id', $activeAcademicYear->id)
                        ->where('enrollments.is_active', true)
                        ->where('enrollments.status', 'active'),
                    'current_students_count'
                ),
                fn ($query) => $query->selectRaw('0 as current_students_count')
            )
            ->orderBy('id')
            ->get();

        $schoolClasses = SchoolClass::query()
            ->select('classes.*')
            ->whereIn('grade_id', $grades->modelKeys())
            ->when(
                $activeAcademicYear,
                fn ($query) => $query->selectSub(
                    Enrollment::query()
                        ->selectRaw('COUNT(DISTINCT enrollments.student_id)')
                        ->whereColumn('enrollments.class_id', 'classes.id')
                        ->where('enrollments.stage_id', $stage->id)
                        ->where('enrollments.academic_year_id', $activeAcademicYear->id)
                        ->where('enrollments.is_active', true)
                        ->where('enrollments.status', 'active'),
                    'current_students_count'
                ),
                fn ($query) => $query->selectRaw('0 as current_students_count')
            )
            ->orderBy('grade_id')
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        $schoolClassesByGrade = $schoolClasses->groupBy('grade_id');

        $grades->each(function (Grade $grade) use ($schoolClassesByGrade) {
            $grade->setRelation('classes', $schoolClassesByGrade->get($grade->id, collect()));
        });

        $currentStudentsCount = $activeAcademicYear
            ? Enrollment::query()
                ->where('stage_id', $stage->id)
                ->where('academic_year_id', $activeAcademicYear->id)
                ->where('is_active', true)
                ->where('status', 'active')
                ->distinct('student_id')
                ->count('student_id')
            : 0;

        $stage->setRelation('grades', $grades);
        $stage->setAttribute('grades_count', $grades->count());
        $stage->setAttribute('school_classes_count', $schoolClasses->count());
        $stage->setAttribute('current_students_count', $currentStudentsCount);

        return view('dashboard.stages.show', compact('stage', 'activeAcademicYear'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required'
        ]);

        Stage::create([
            'name' => $request->name,
            'description' => $request->description,
            'order' => $request->order ?? 1,
            'is_active' => 1
        ]);

        return redirect()
            ->route('dashboard.stages.index')
            ->with('success','Stage created');
    }

    public function edit(Stage $stage)
    {
        return view('dashboard.stages.edit', compact('stage'));
    }

    public function update(Request $request, Stage $stage)
    {
        $stage->update($request->all());

        return redirect()
            ->route('dashboard.stages.index')
            ->with('success','Stage updated');
    }

    public function destroy(Stage $stage, AcademicCoreDeletionGuard $guard)
    {
        $guard->ensureCanDelete($stage);
        $stage->delete();

        return back()->with('success', __('stages.deleted_success'));
    }
}

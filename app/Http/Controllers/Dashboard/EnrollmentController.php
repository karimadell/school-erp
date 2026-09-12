<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\AcademicYear;
use App\Models\Stage;
use App\Models\Grade;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\AcademicStructureService;
use App\Services\Admissions\EnrollmentService;

class EnrollmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view enrollments')->only(['index', 'history']);
        $this->middleware('permission:create enrollments')->only(['create', 'store']);
        $this->middleware('permission:update enrollments')->only(['edit', 'update']);
        $this->middleware('permission:delete enrollments')->only(['destroy']);
    }

    public function index(): View
    {
        $enrollments = Enrollment::with([
                'student',
                'academicYear',
                'stage',
                'grade',
                'schoolClass',
            ])
            ->latest('enrollment_date')
            ->latest('id')
            ->paginate(20);

        return view('dashboard.enrollments.index', compact('enrollments'));
    }

    public function create(Student $student): View
    {
        return view('dashboard.enrollments.create', [
            'student' => $student,

            'academicYears' => class_exists(AcademicYear::class)
                ? AcademicYear::orderBy('id')->get()
                : collect(),

            'stages' => Stage::orderBy('id')->get(),

            'grades' => Grade::with('stage')
                ->orderBy('stage_id')
                ->ordered()
                ->get(),

            'classes' => SchoolClass::with('grade')
                ->orderBy('name_ru')
                ->get(),

            'statuses' => $this->statuses(),
        ]);
    }

    public function store(Request $request, Student $student, EnrollmentService $enrollments): RedirectResponse
    {
        $data = $request->validate([
            'academic_year_id' => [
                'required',
                'exists:academic_years,id',
                // Backs the DB-level unique(student_id, academic_year_id)
                // constraint (present since the first enrollments migration)
                // with a friendly validation error instead of letting a
                // duplicate submission crash with an uncaught QueryException.
                Rule::unique('enrollments', 'academic_year_id')
                    ->where(fn ($query) => $query->where('student_id', $student->id)),
            ],
            'enrollment_mode_id' => ['nullable', 'exists:enrollment_modes,id'],

            'stage_id' => ['required', 'exists:stages,id'],
            'grade_id' => ['required', 'exists:grades,id'],
            'class_id' => ['required', 'exists:classes,id'],

            'enrollment_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,transferred,withdrawn,graduated'],
            'notes' => ['nullable', 'string'],
        ], [
            'academic_year_id.unique' => __('enrollments.duplicate_year'),
        ]);

        // Existing Student Enrollment Extraction (Phase 1) — the domain
        // behavior (resolving the target year, placement validation,
        // creating the Enrollment row, syncing Student.class_id) now lives
        // in EnrollmentService::create(), unchanged, so it can be reused by
        // a future Existing Student → New Academic Year workflow. This
        // controller keeps request validation (including the duplicate-year
        // Rule::unique check above), authorization, and the redirect/flash
        // response exactly as before.
        $enrollments->create($student, $data);

        return redirect()
            ->route('dashboard.students.show', $student->id)
            ->with('success', __('enrollments.created_success'));
    }

    public function history(Student $student): View
    {
        $enrollments = Enrollment::with([
                'academicYear',
                'stage',
                'grade',
                'schoolClass',
            ])
            ->where('student_id', $student->id)
            ->latest('enrollment_date')
            ->latest('id')
            ->paginate(20);

        return view('dashboard.enrollments.history', compact('student', 'enrollments'));
    }

    public function edit(string $id): View
    {
        $enrollment = Enrollment::with('student')->findOrFail($id);

        return view('dashboard.enrollments.edit', [
            'enrollment' => $enrollment,
            'student' => $enrollment->student,

            'academicYears' => class_exists(AcademicYear::class)
                ? AcademicYear::orderBy('id')->get()
                : collect(),

            'stages' => Stage::orderBy('id')->get(),

            'grades' => Grade::with('stage')
                ->orderBy('stage_id')
                ->ordered()
                ->get(),

            'classes' => SchoolClass::with('grade')
                ->orderBy('name_ru')
                ->get(),

            'statuses' => $this->statuses(),
        ]);
    }

    public function update(Request $request, string $id, AcademicStructureService $structure): RedirectResponse
    {
        $enrollment = Enrollment::findOrFail($id);

        $data = $request->validate([
            'academic_year_id' => [
                'required',
                'exists:academic_years,id',
                Rule::unique('enrollments', 'academic_year_id')
                    ->where(fn ($query) => $query->where('student_id', $enrollment->student_id))
                    ->ignore($enrollment->id),
            ],
            'enrollment_mode_id' => ['nullable', 'exists:enrollment_modes,id'],

            'stage_id' => ['required', 'exists:stages,id'],
            'grade_id' => ['required', 'exists:grades,id'],
            'class_id' => ['required', 'exists:classes,id'],

            'enrollment_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,transferred,withdrawn,graduated'],
            'notes' => ['nullable', 'string'],
        ], [
            'academic_year_id.unique' => __('enrollments.duplicate_year'),
        ]);

        // The academic_year label is derived server-side from the selected year
        // (see store()), never from a hand-typed value.
        $year = AcademicYear::findOrFail($data['academic_year_id']);
        $structure->validatePlacement(
            (int) $data['stage_id'],
            (int) $data['grade_id'],
            (int) $data['class_id'],
            requireActive: true,
        );

        DB::transaction(function () use ($data, $enrollment, $year) {
            // Updating this enrollment never deactivates another academic
            // year's enrollment — see the equivalent note in store().
            $enrollment->update([
                'academic_year_id' => $data['academic_year_id'],
                'academic_year' => $year->name,
                'enrollment_mode_id' => $data['enrollment_mode_id'] ?? null,

                'stage_id' => $data['stage_id'],
                'grade_id' => $data['grade_id'],
                'class_id' => $data['class_id'],

                'enrollment_date' => $data['enrollment_date'] ?? now()->toDateString(),
                'enrolled_at' => $data['enrollment_date'] ?? now()->toDateString(),

                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['status'] === 'active',
            ]);

            if ($data['status'] === 'active' && $year->is_active) {
                $enrollment->student?->update([
                    'class_id' => $data['class_id'],
                ]);
            }
        });

        return redirect()
            ->route('dashboard.enrollments.index')
            ->with('success', __('enrollments.updated_success'));
    }

    public function destroy(string $id): RedirectResponse
    {
        $enrollment = Enrollment::findOrFail($id);
        $enrollment->delete();

        return redirect()
            ->route('dashboard.enrollments.index')
            ->with('success', __('enrollments.deleted_success'));
    }

    private function statuses(): array
    {
        return [
            'active' => __('enrollments.status_active'),
            'transferred' => __('enrollments.status_transferred'),
            'withdrawn' => __('enrollments.status_withdrawn'),
            'graduated' => __('enrollments.status_graduated'),
        ];
    }
}

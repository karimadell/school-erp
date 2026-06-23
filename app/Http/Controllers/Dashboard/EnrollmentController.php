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

class EnrollmentController extends Controller
{
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
                ->orderBy('id')
                ->get(),

            'classes' => SchoolClass::with('grade')
                ->orderBy('name_ru')
                ->get(),

            'statuses' => $this->statuses(),
        ]);
    }

    public function store(Request $request, Student $student): RedirectResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['nullable', 'exists:academic_years,id'],
            'academic_year' => ['nullable', 'string', 'max:50'],

            'stage_id' => ['required', 'exists:stages,id'],
            'grade_id' => ['required', 'exists:grades,id'],
            'class_id' => ['required', 'exists:classes,id'],

            'enrollment_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,transferred,withdrawn,graduated'],
            'notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($data, $student) {
            if ($data['status'] === 'active') {
                Enrollment::where('student_id', $student->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'status' => 'transferred',
                    ]);
            }

            Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $data['academic_year_id'] ?? null,
                'academic_year' => $data['academic_year'] ?? null,

                'stage_id' => $data['stage_id'],
                'grade_id' => $data['grade_id'],
                'class_id' => $data['class_id'],

                'enrollment_date' => $data['enrollment_date'] ?? now()->toDateString(),
                'enrolled_at' => $data['enrollment_date'] ?? now()->toDateString(),

                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['status'] === 'active',
            ]);

            if ($data['status'] === 'active') {
                $student->update([
                    'class_id' => $data['class_id'],
                ]);
            }
        });

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
                ->orderBy('id')
                ->get(),

            'classes' => SchoolClass::with('grade')
                ->orderBy('name_ru')
                ->get(),

            'statuses' => $this->statuses(),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $enrollment = Enrollment::findOrFail($id);

        $data = $request->validate([
            'academic_year_id' => ['nullable', 'exists:academic_years,id'],
            'academic_year' => ['nullable', 'string', 'max:50'],

            'stage_id' => ['required', 'exists:stages,id'],
            'grade_id' => ['required', 'exists:grades,id'],
            'class_id' => ['required', 'exists:classes,id'],

            'enrollment_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,transferred,withdrawn,graduated'],
            'notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($data, $enrollment) {
            if ($data['status'] === 'active') {
                Enrollment::where('student_id', $enrollment->student_id)
                    ->where('id', '!=', $enrollment->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'status' => 'transferred',
                    ]);
            }

            $enrollment->update([
                'academic_year_id' => $data['academic_year_id'] ?? null,
                'academic_year' => $data['academic_year'] ?? null,

                'stage_id' => $data['stage_id'],
                'grade_id' => $data['grade_id'],
                'class_id' => $data['class_id'],

                'enrollment_date' => $data['enrollment_date'] ?? now()->toDateString(),
                'enrolled_at' => $data['enrollment_date'] ?? now()->toDateString(),

                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['status'] === 'active',
            ]);

            if ($data['status'] === 'active') {
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
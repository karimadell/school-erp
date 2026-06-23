<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\StudentGrade;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Exam;
use App\Models\SchoolClass;
use App\Exports\StudentGradesReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class StudentGradeController extends Controller
{
    public function index()
    {
        $grades = StudentGrade::with(['student', 'subject', 'exam', 'quarter'])
            ->latest()
            ->get();

        return view('dashboard.student-grades.index', compact('grades'));
    }

    public function create()
    {
        $students = Student::orderBy('last_name_ru')
            ->orderBy('first_name_ru')
            ->orderBy('patronymic_ru')
            ->get();

        $subjects = Subject::orderBy('name_ru')->get();
        $exams = Exam::orderBy('name')->get();

        $quarters = class_exists(\App\Models\Quarter::class)
            ? \App\Models\Quarter::orderBy('id')->get()
            : collect();

        return view('dashboard.student-grades.create', compact('students', 'subjects', 'exams', 'quarters'));
    }

    public function store(Request $request)
    {
        $rules = [
            'student_id' => 'required|exists:students,id',
            'subject_id' => 'required|exists:subjects,id',
            'exam_id' => 'required|exists:exams,id',
            'score' => 'required|numeric|min:0|max:100',
            'note' => 'nullable|string|max:255',
        ];

        if (class_exists(\App\Models\Quarter::class)) {
            $rules['quarter_id'] = 'nullable|exists:quarters,id';
        }

        $request->validate($rules);

        StudentGrade::create([
            'student_id' => $request->student_id,
            'subject_id' => $request->subject_id,
            'exam_id' => $request->exam_id,
            'quarter_id' => $request->quarter_id,
            'score' => $request->score,
            'note' => $request->note,
        ]);

        return redirect()
            ->route('dashboard.student-grades.index')
            ->with('success', __('student_grades.created_success'));
    }

    public function show($id)
    {
        return redirect()->route('dashboard.student-grades.edit', $id);
    }

    public function edit($id)
    {
        $grade = StudentGrade::findOrFail($id);

        $students = Student::orderBy('last_name_ru')
            ->orderBy('first_name_ru')
            ->orderBy('patronymic_ru')
            ->get();

        $subjects = Subject::orderBy('name_ru')->get();
        $exams = Exam::orderBy('name')->get();

        $quarters = class_exists(\App\Models\Quarter::class)
            ? \App\Models\Quarter::orderBy('id')->get()
            : collect();

        return view('dashboard.student-grades.edit', compact('grade', 'students', 'subjects', 'exams', 'quarters'));
    }

    public function update(Request $request, $id)
    {
        $rules = [
            'student_id' => 'required|exists:students,id',
            'subject_id' => 'required|exists:subjects,id',
            'exam_id' => 'required|exists:exams,id',
            'score' => 'required|numeric|min:0|max:100',
            'note' => 'nullable|string|max:255',
        ];

        if (class_exists(\App\Models\Quarter::class)) {
            $rules['quarter_id'] = 'nullable|exists:quarters,id';
        }

        $request->validate($rules);

        $grade = StudentGrade::findOrFail($id);

        $grade->update([
            'student_id' => $request->student_id,
            'subject_id' => $request->subject_id,
            'exam_id' => $request->exam_id,
            'quarter_id' => $request->quarter_id,
            'score' => $request->score,
            'note' => $request->note,
        ]);

        return redirect()
            ->route('dashboard.student-grades.index')
            ->with('success', __('student_grades.updated_success'));
    }

    public function destroy($id)
    {
        $grade = StudentGrade::findOrFail($id);
        $grade->delete();

        return redirect()
            ->route('dashboard.student-grades.index')
            ->with('success', __('student_grades.deleted_success'));
    }

    public function bulkForm()
    {
        $classes = SchoolClass::with('grade.stage')
            ->orderBy('grade_id')
            ->orderBy('name_ru')
            ->get();

        $subjects = Subject::orderBy('name_ru')->get();
        $exams = Exam::orderBy('name')->get();

        $quarters = class_exists(\App\Models\Quarter::class)
            ? \App\Models\Quarter::orderBy('id')->get()
            : collect();

        return view('dashboard.student-grades.bulk', compact('classes', 'subjects', 'exams', 'quarters'));
    }

    public function bulkStudents(Request $request)
    {
        $rules = [
            'class_id' => 'required|exists:classes,id',
            'subject_id' => 'required|exists:subjects,id',
            'exam_id' => 'required|exists:exams,id',
        ];

        if (class_exists(\App\Models\Quarter::class)) {
            $rules['quarter_id'] = 'nullable|exists:quarters,id';
        }

        $request->validate($rules);

        $students = Student::where('class_id', $request->class_id)
            ->orderBy('last_name_ru')
            ->orderBy('first_name_ru')
            ->orderBy('patronymic_ru')
            ->get();

        $gradesQuery = StudentGrade::whereIn('student_id', $students->pluck('id'))
            ->where('subject_id', $request->subject_id)
            ->where('exam_id', $request->exam_id);

        if (class_exists(\App\Models\Quarter::class)) {
            if ($request->filled('quarter_id')) {
                $gradesQuery->where('quarter_id', $request->quarter_id);
            } else {
                $gradesQuery->whereNull('quarter_id');
            }
        }

        $existingGrades = $gradesQuery->get()->keyBy('student_id');

        return response()->json([
            'students' => $students->map(function ($student) use ($existingGrades) {
                $existing = $existingGrades->get($student->id);

                return [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'score' => $existing?->score,
                    'note' => $existing?->note,
                ];
            })->values(),
        ]);
    }

    public function bulkStore(Request $request)
    {
        $rules = [
            'class_id' => 'required|exists:classes,id',
            'subject_id' => 'required|exists:subjects,id',
            'exam_id' => 'required|exists:exams,id',
            'grades' => 'required|array',
        ];

        if (class_exists(\App\Models\Quarter::class)) {
            $rules['quarter_id'] = 'nullable|exists:quarters,id';
        }

        $request->validate($rules);

        $validStudentIds = Student::where('class_id', $request->class_id)
            ->pluck('id')
            ->toArray();

        foreach ($request->grades as $studentId => $data) {
            if (!in_array((int) $studentId, $validStudentIds, true)) {
                continue;
            }

            $score = $data['score'] ?? null;
            $note = $data['note'] ?? null;

            if (($score === null || $score === '') && ($note === null || $note === '')) {
                continue;
            }

            $attributes = [
                'student_id' => $studentId,
                'subject_id' => $request->subject_id,
                'exam_id' => $request->exam_id,
            ];

            if (class_exists(\App\Models\Quarter::class)) {
                $attributes['quarter_id'] = $request->quarter_id ?: null;
            }

            StudentGrade::updateOrCreate(
                $attributes,
                [
                    'score' => $score === null || $score === '' ? 0 : $score,
                    'note' => $note,
                ]
            );
        }

        return redirect()
            ->route('dashboard.student-grades.bulk.form')
            ->with('success', __('student_grades.bulk_saved_success'));
    }

    public function reportForm()
    {
        $classes = SchoolClass::with('grade.stage')
            ->orderBy('grade_id')
            ->orderBy('name_ru')
            ->get();

        $subjects = Subject::orderBy('name_ru')->get();
        $exams = Exam::orderBy('name')->get();

        $quarters = class_exists(\App\Models\Quarter::class)
            ? \App\Models\Quarter::orderBy('id')->get()
            : collect();

        return view('dashboard.student-grades.report', compact(
            'classes',
            'subjects',
            'exams',
            'quarters'
        ));
    }

    public function reportGenerate(Request $request)
    {
        [$students, $grades, $data, $class, $subject, $exam, $quarter] = $this->buildReportData($request);

        return view('dashboard.student-grades.print', compact(
            'students',
            'grades',
            'data',
            'class',
            'subject',
            'exam',
            'quarter'
        ));
    }

    public function reportPdf(Request $request)
    {
        [$students, $grades, $data, $class, $subject, $exam, $quarter] = $this->buildReportData($request);

        $pdf = Pdf::loadView('dashboard.student-grades.pdf', compact(
            'students',
            'grades',
            'data',
            'class',
            'subject',
            'exam',
            'quarter'
        ))->setPaper('a4', 'landscape');

        return $pdf->download('student-grades-report.pdf');
    }

    public function reportExcel(Request $request)
    {
        return Excel::download(
            new StudentGradesReportExport($request),
            'student-grades-report.xlsx'
        );
    }

    private function buildReportData(Request $request): array
    {
        $data = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'subject_id' => 'nullable|exists:subjects,id',
            'exam_id' => 'nullable|exists:exams,id',
            'quarter_id' => 'nullable',
            'columns' => 'required|array',
        ]);

        $students = Student::with('class.grade.stage')
            ->where('class_id', $data['class_id'])
            ->orderBy('last_name_ru')
            ->orderBy('first_name_ru')
            ->orderBy('patronymic_ru')
            ->get();

        $gradesQuery = StudentGrade::whereIn('student_id', $students->pluck('id'));

        if (!empty($data['subject_id'])) {
            $gradesQuery->where('subject_id', $data['subject_id']);
        }

        if (!empty($data['exam_id'])) {
            $gradesQuery->where('exam_id', $data['exam_id']);
        }

        if (class_exists(\App\Models\Quarter::class) && !empty($data['quarter_id'])) {
            $gradesQuery->where('quarter_id', $data['quarter_id']);
        }

        $grades = $gradesQuery->get()->keyBy('student_id');

        $class = SchoolClass::with('grade.stage')->find($data['class_id']);
        $subject = !empty($data['subject_id']) ? Subject::find($data['subject_id']) : null;
        $exam = !empty($data['exam_id']) ? Exam::find($data['exam_id']) : null;

        $quarter = null;
        if (class_exists(\App\Models\Quarter::class) && !empty($data['quarter_id'])) {
            $quarter = \App\Models\Quarter::find($data['quarter_id']);
        }

        return [$students, $grades, $data, $class, $subject, $exam, $quarter];
    }
}
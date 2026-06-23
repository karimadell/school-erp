<?php

namespace App\Exports;

use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Exam;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentGradesReportExport implements FromArray, WithHeadings, ShouldAutoSize
{
    protected array $data;
    protected $students;
    protected $grades;
    protected $class;
    protected $subject;
    protected $exam;
    protected $quarter;

    public function __construct(Request $request)
    {
        $this->data = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'subject_id' => 'nullable|exists:subjects,id',
            'exam_id' => 'nullable|exists:exams,id',
            'quarter_id' => 'nullable',
            'columns' => 'required|array',
        ]);

        $this->students = Student::with('class.grade.stage')
            ->where('class_id', $this->data['class_id'])
            ->orderBy('last_name_ru')
            ->orderBy('first_name_ru')
            ->orderBy('patronymic_ru')
            ->get();

        $gradesQuery = StudentGrade::whereIn('student_id', $this->students->pluck('id'));

        if (!empty($this->data['subject_id'])) {
            $gradesQuery->where('subject_id', $this->data['subject_id']);
        }

        if (!empty($this->data['exam_id'])) {
            $gradesQuery->where('exam_id', $this->data['exam_id']);
        }

        if (class_exists(\App\Models\Quarter::class) && !empty($this->data['quarter_id'])) {
            $gradesQuery->where('quarter_id', $this->data['quarter_id']);
        }

        $this->grades = $gradesQuery->get()->keyBy('student_id');

        $this->class = SchoolClass::with('grade.stage')->find($this->data['class_id']);
        $this->subject = !empty($this->data['subject_id']) ? Subject::find($this->data['subject_id']) : null;
        $this->exam = !empty($this->data['exam_id']) ? Exam::find($this->data['exam_id']) : null;

        $this->quarter = null;
        if (class_exists(\App\Models\Quarter::class) && !empty($this->data['quarter_id'])) {
            $this->quarter = \App\Models\Quarter::find($this->data['quarter_id']);
        }
    }

    public function headings(): array
    {
        $headings = ['#'];

        foreach ($this->data['columns'] as $column) {
            $headings[] = __('student_grades.columns.' . $column);
        }

        return $headings;
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->students as $index => $student) {
            $grade = $this->grades->get($student->id);

            $row = [
                $index + 1,
            ];

            foreach ($this->data['columns'] as $column) {
                $row[] = match ($column) {
                    'student_name' => $student->full_name,
                    'short_name' => $student->short_name,
                    'class' => $student->class->name_ru ?? '-',
                    'phone' => $student->parent_phone ?? $student->phone ?? '-',
                    'email' => $student->email ?? '-',
                    'nationality' => $student->nationality ?? '-',
                    'birth_date' => optional($student->birth_date)->format('Y-m-d') ?? '-',
                    'gender' => $student->gender ? __('students.' . $student->gender) : '-',
                    'subject' => $this->subject->name_ru ?? $this->subject->name ?? '-',
                    'exam' => $this->exam->name ?? $this->exam->title ?? '-',
                    'quarter' => $this->quarter->name ?? $this->quarter->title ?? '-',
                    'score' => $grade->score ?? '-',
                    'note' => $grade->note ?? '-',
                    default => '-',
                };
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
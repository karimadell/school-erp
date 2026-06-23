<?php

namespace App\Filament\Teacher\Pages;

use Filament\Pages\Page;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\Subject;
use App\Models\Quarter;
use UnitEnum;
use BackedEnum;

class TeacherGrades extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static string|UnitEnum|null $navigationGroup = 'Преподаватель';

    protected static ?string $navigationLabel = 'Оценки';

    protected string $view = 'filament.teacher.pages.teacher-grades';

    public $classId;
    public $subjectId;
    public $quarterId;

    public $students = [];
    public $grades = [];

    public function loadStudents()
    {
        $this->students = Student::where('class_id', $this->classId)->get();

        foreach ($this->students as $student) {

            $grade = StudentGrade::where('student_id', $student->id)
                ->where('subject_id', $this->subjectId)
                ->where('quarter_id', $this->quarterId)
                ->first();

            $this->grades[$student->id] = $grade->score ?? '';
        }
    }

    public function saveGrades()
    {
        foreach ($this->grades as $studentId => $score) {

            StudentGrade::updateOrCreate(
                [
                    'student_id' => $studentId,
                    'subject_id' => $this->subjectId,
                    'quarter_id' => $this->quarterId,
                ],
                [
                    'score' => $score
                ]
            );
        }

        $this->notify('success', 'Оценки сохранены');
    }
}
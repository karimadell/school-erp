<?php

namespace App\Filament\Teacher\Pages;

use Filament\Pages\Page;
use App\Models\Student;
use App\Models\Attendance;
use Carbon\Carbon;
use UnitEnum;
use BackedEnum;

class TeacherAttendance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-check-circle';

    protected static string|UnitEnum|null $navigationGroup = 'Преподаватель';

    protected static ?string $navigationLabel = 'Посещаемость';

    protected string $view = 'filament.teacher.pages.teacher-attendance';

    public $classId;

    public $students = [];

    public $attendance = [];

    public function loadStudents()
    {
        $this->students = Student::where('class_id', $this->classId)->get();

        foreach ($this->students as $student) {
            $this->attendance[$student->id] = 'present';
        }
    }

    public function saveAttendance()
    {
        foreach ($this->attendance as $studentId => $status) {

            Attendance::updateOrCreate(
                [
                    'student_id' => $studentId,
                    'date' => Carbon::today(),
                ],
                [
                    'status' => $status
                ]
            );
        }

        $this->notify('success','Attendance saved');
    }
}
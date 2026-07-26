<?php

namespace App\Filament\Teacher\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use App\Models\Enrollment;
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
        // Attendance is keyed via Enrollment (enrollment_id), not Student
        // directly — matches AttendanceController::take()'s query.
        $this->students = Enrollment::with('student')
            ->where('class_id', $this->classId)
            ->get();

        foreach ($this->students as $enrollment) {
            $this->attendance[$enrollment->id] = 'present';
        }
    }

    public function saveAttendance()
    {
        $this->validate([
            'attendance.*' => 'required|in:present,absent,late,excused',
        ]);

        $date = Carbon::today()->toDateString();

        foreach ($this->attendance as $enrollmentId => $status) {

            Attendance::updateOrCreate(
                ['attendance_key' => Attendance::buildAttendanceKey('daily', (int) $enrollmentId, $date)],
                [
                    'enrollment_id' => $enrollmentId,
                    'date' => $date,
                    'type' => 'daily',
                    'status' => $status,
                ]
            );
        }

        Notification::make()
            ->title('Attendance saved')
            ->success()
            ->send();
    }
}

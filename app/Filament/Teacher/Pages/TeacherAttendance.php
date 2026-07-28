<?php

namespace App\Filament\Teacher\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use App\Models\Enrollment;
use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Teacher;
use Illuminate\Support\Facades\Auth;
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

    public $assignedClasses = [];

    public function mount(): void
    {
        $teacher = $this->currentTeacher();

        if ($teacher) {
            $this->assignedClasses = SchoolClass::whereIn(
                'id',
                $teacher->currentAssignments()->pluck('class_id')
            )->get();
        }
    }

    /**
     * Batch 8: resolves the logged-in User to their Teacher record. Every
     * scoping check below is keyed off this, never off client-supplied
     * input.
     */
    protected function currentTeacher(): ?Teacher
    {
        return Teacher::where('user_id', Auth::id())->first();
    }

    /**
     * Batch 8: previously loaded any class by ID with zero ownership
     * check — $this->classId is unguarded Livewire state, settable by
     * any teacher to any class. Now denied unless the teacher is
     * actually assigned to that class this academic year.
     */
    protected function authorizeClassAccess(): bool
    {
        $teacher = $this->currentTeacher();

        if (! $teacher || ! $this->classId || ! $teacher->isAssignedToClass((int) $this->classId)) {
            Notification::make()
                ->title('Вы не назначены на этот класс')
                ->danger()
                ->send();

            return false;
        }

        return true;
    }

    public function loadStudents()
    {
        if (! $this->authorizeClassAccess()) {
            $this->students = [];

            return;
        }

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
        // Re-checked here, not just in loadStudents(): classId is client
        // state and could be tampered with between load and save.
        if (! $this->authorizeClassAccess()) {
            return;
        }

        $this->validate([
            'attendance.*' => 'required|in:present,absent,late,excused',
        ]);

        $date = Carbon::today()->toDateString();

        foreach ($this->attendance as $enrollmentId => $status) {

            // Every enrollment being saved must actually belong to the
            // authorized class — otherwise a tampered attendance payload
            // could target enrollments from an unrelated class while
            // classId itself passes the check above.
            $enrollment = Enrollment::where('id', $enrollmentId)
                ->where('class_id', $this->classId)
                ->first();

            if (! $enrollment) {
                continue;
            }

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

<?php

namespace App\Filament\Teacher\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\Subject;
use App\Models\Quarter;
use App\Models\Teacher;
use Illuminate\Support\Facades\Auth;
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

    public $assignedClasses = [];
    public $assignedSubjects = [];

    public function mount(): void
    {
        $teacher = $this->currentTeacher();

        if ($teacher) {
            $assignments = $teacher->currentAssignments()->get();

            $this->assignedClasses = SchoolClass::whereIn('id', $assignments->pluck('class_id'))->get();
            $this->assignedSubjects = Subject::whereIn('id', $assignments->pluck('subject_id'))->get();
        }
    }

    protected function currentTeacher(): ?Teacher
    {
        return Teacher::where('user_id', Auth::id())->first();
    }

    /**
     * Batch 8: previously loaded/saved any class+subject combination with
     * zero ownership check. Now denied unless the teacher is actually
     * assigned to that class and subject this academic year.
     */
    protected function authorizeClassSubjectAccess(): bool
    {
        $teacher = $this->currentTeacher();

        if (
            ! $teacher
            || ! $this->classId
            || ! $this->subjectId
            || ! $teacher->isAssignedToClassSubject((int) $this->classId, (int) $this->subjectId)
        ) {
            Notification::make()
                ->title('Вы не назначены на этот класс и предмет')
                ->danger()
                ->send();

            return false;
        }

        return true;
    }

    public function loadStudents()
    {
        if (! $this->authorizeClassSubjectAccess()) {
            $this->students = [];

            return;
        }

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
        // Re-checked here, not just in loadStudents(): classId/subjectId
        // are client state and could be tampered with between load and
        // save.
        if (! $this->authorizeClassSubjectAccess()) {
            return;
        }

        foreach ($this->grades as $studentId => $score) {

            // Every student being graded must actually belong to the
            // authorized class — otherwise a tampered grades payload
            // could target students from an unrelated class while
            // classId itself passes the check above.
            $student = Student::where('id', $studentId)
                ->where('class_id', $this->classId)
                ->first();

            if (! $student) {
                continue;
            }

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

        Notification::make()
            ->title('Оценки сохранены')
            ->success()
            ->send();
    }
}

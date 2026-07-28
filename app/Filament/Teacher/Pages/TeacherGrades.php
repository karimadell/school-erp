<?php

namespace App\Filament\Teacher\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\Quarter;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\Subject;
use App\Models\Teacher;
use App\Rules\QuarterBelongsToActiveYear;
use Illuminate\Support\Facades\Auth;
use UnitEnum;
use BackedEnum;

class TeacherGrades extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected string $view = 'filament.teacher.pages.teacher-grades';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('teacher_portal.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('teacher_portal.nav_grades');
    }

    public $classId;
    public $subjectId;
    public $examId;

    public $students = [];
    public $grades = [];

    public $assignedClasses = [];
    public $assignedSubjects = [];
    public $exams = [];
    public $quarters = [];

    public $newExamName;
    public $newExamDate;
    public $newExamMaxScore = 100;
    public $newExamQuarterId;

    public function mount(): void
    {
        $teacher = $this->currentTeacher();

        if ($teacher) {
            $assignments = $teacher->currentAssignments()->get();

            $this->assignedClasses = SchoolClass::whereIn('id', $assignments->pluck('class_id'))->get();
            $this->assignedSubjects = Subject::whereIn('id', $assignments->pluck('subject_id'))->get();
        }

        $activeYearId = AcademicYear::where('is_active', true)->value('id');
        $this->quarters = $activeYearId
            ? Quarter::where('academic_year_id', $activeYearId)->orderBy('order')->get()
            : collect();
    }

    protected function currentTeacher(): ?Teacher
    {
        return Teacher::where('user_id', Auth::id())->first();
    }

    /**
     * Batch 8/9: previously loaded/saved any class+subject combination
     * with zero ownership check. Now denied unless the teacher is
     * actually assigned to that class and subject this academic year.
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
                ->title(__('teacher_portal.not_assigned_to_class_subject'))
                ->danger()
                ->send();

            return false;
        }

        return true;
    }

    public function loadExams(): void
    {
        if (! $this->authorizeClassSubjectAccess()) {
            $this->exams = [];

            return;
        }

        $this->exams = Exam::where('class_id', $this->classId)
            ->where('subject_id', $this->subjectId)
            ->orderByDesc('exam_date')
            ->get();
    }

    public function createExam(): void
    {
        if (! $this->authorizeClassSubjectAccess()) {
            return;
        }

        $this->validate([
            'newExamName' => 'required|string|max:255',
            'newExamDate' => 'nullable|date',
            'newExamMaxScore' => 'required|integer|min:1',
            'newExamQuarterId' => ['nullable', 'exists:quarters,id', new QuarterBelongsToActiveYear()],
        ]);

        $exam = Exam::create([
            'name' => $this->newExamName,
            'class_id' => $this->classId,
            'subject_id' => $this->subjectId,
            'quarter_id' => $this->newExamQuarterId ?: null,
            'exam_date' => $this->newExamDate ?: null,
            'max_score' => $this->newExamMaxScore,
        ]);

        $this->loadExams();
        $this->examId = $exam->id;

        $this->newExamName = null;
        $this->newExamDate = null;
        $this->newExamMaxScore = 100;
        $this->newExamQuarterId = null;

        Notification::make()
            ->title(__('teacher_grades.exam_created_success'))
            ->success()
            ->send();
    }

    /**
     * Every exam a teacher acts on must actually belong to their
     * authorized class+subject — examId is client state and could be
     * tampered with to point at an exam from an unrelated class.
     */
    protected function authorizeExamAccess(): ?Exam
    {
        if (! $this->authorizeClassSubjectAccess() || ! $this->examId) {
            return null;
        }

        return Exam::where('id', $this->examId)
            ->where('class_id', $this->classId)
            ->where('subject_id', $this->subjectId)
            ->first();
    }

    public function loadStudents(): void
    {
        $exam = $this->authorizeExamAccess();

        if (! $exam) {
            $this->students = [];

            return;
        }

        $this->students = Student::where('class_id', $this->classId)->get();

        foreach ($this->students as $student) {

            $grade = StudentGrade::where('student_id', $student->id)
                ->where('exam_id', $exam->id)
                ->first();

            $this->grades[$student->id] = $grade->score ?? '';
        }
    }

    public function saveGrades(): void
    {
        // Re-checked here, not just in loadStudents(): classId/subjectId/
        // examId are client state and could be tampered with between
        // load and save.
        $exam = $this->authorizeExamAccess();

        if (! $exam) {
            return;
        }

        foreach ($this->grades as $studentId => $score) {

            if ($score === null || $score === '') {
                continue;
            }

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
                    'exam_id' => $exam->id,
                ],
                [
                    'quarter_id' => $exam->quarter_id,
                    'score' => $score,
                ]
            );
        }

        Notification::make()
            ->title(__('teacher_grades.saved_success'))
            ->success()
            ->send();
    }
}

<?php

namespace Tests\Feature\Teacher;

use App\Filament\Teacher\Pages\TeacherGrades;
use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Quarter;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batch 8/9: TeacherGrades previously loaded/saved grades for any
 * class+subject combination with zero ownership check, and (Batch 9)
 * was rewritten from a quarter-only shortcut into a proper Exam-linked
 * flow, matching the dashboard's own grading model — no permanent
 * quarter-only path. Quarter context now comes from the selected/created
 * Exam, not a separate teacher-facing dropdown.
 */
class TeacherGradesTest extends TestCase
{
    use RefreshDatabase;

    protected function makeClassWithStudent(AcademicYear $year, string $studentName = 'Test Student'): array
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A-' . uniqid(),
            'name_ar' => 'فصل',
            'name_ru' => 'Класс',
        ]);
        $student = Student::create(['name' => $studentName, 'class_id' => $class->id]);

        return [$class, $student];
    }

    protected function makeYear(bool $active = true): AcademicYear
    {
        return AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => $active,
        ]);
    }

    protected function makeSubject(): Subject
    {
        return Subject::create(['code' => 'SUBJ-' . uniqid(), 'name_ar' => 'مادة', 'name_ru' => 'Предмет']);
    }

    protected function makeExam(int $classId, int $subjectId, ?int $quarterId = null): Exam
    {
        return Exam::create([
            'name' => 'Quiz ' . uniqid(),
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'quarter_id' => $quarterId,
            'max_score' => 100,
        ]);
    }

    protected function linkTeacher(User $user, int $classId, int $subjectId, int $academicYearId): Teacher
    {
        $teacher = Teacher::create([
            'user_id' => $user->id,
            'first_name' => 'Anna',
            'last_name' => 'Ivanova',
            'is_active' => true,
        ]);

        TeacherAssignment::create([
            'teacher_id' => $teacher->id,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'academic_year_id' => $academicYearId,
        ]);

        return $teacher;
    }

    /*
    |--------------------------------------------------------------------------
    | Assigned teacher: positive path — Exam-linked grade entry
    |--------------------------------------------------------------------------
    */

    public function test_an_assigned_teacher_can_load_and_save_grades_for_an_existing_exam(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        [$class, $student] = $this->makeClassWithStudent($year);
        $exam = $this->makeExam($class->id, $subject->id);
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $subject->id, $year->id);

        Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->set('examId', $exam->id)
            ->call('loadStudents')
            ->set("grades.{$student->id}", 87)
            ->call('saveGrades');

        $this->assertDatabaseHas('student_grades', [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'exam_id' => $exam->id,
            'score' => 87,
        ]);
    }

    /**
     * The exam's quarter must be copied onto the grade row — quarter
     * context is derived through the related Exam, not chosen directly
     * by the teacher.
     */
    public function test_the_grade_inherits_the_exams_quarter(): void
    {
        $year = $this->makeYear();
        $quarter = Quarter::create(['academic_year_id' => $year->id, 'name' => 'Q1', 'order' => 1]);
        $subject = $this->makeSubject();
        [$class, $student] = $this->makeClassWithStudent($year);
        $exam = $this->makeExam($class->id, $subject->id, $quarter->id);
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $subject->id, $year->id);

        Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->set('examId', $exam->id)
            ->call('loadStudents')
            ->set("grades.{$student->id}", 55)
            ->call('saveGrades');

        $this->assertDatabaseHas('student_grades', [
            'student_id' => $student->id,
            'exam_id' => $exam->id,
            'quarter_id' => $quarter->id,
            'score' => 55,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Inline exam creation
    |--------------------------------------------------------------------------
    */

    public function test_an_assigned_teacher_can_create_a_new_exam_for_their_class_and_subject(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        [$class] = $this->makeClassWithStudent($year);
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $subject->id, $year->id);

        Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->set('newExamName', 'Midterm')
            ->set('newExamMaxScore', 50)
            ->call('createExam')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('exams', [
            'name' => 'Midterm',
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'max_score' => 50,
        ]);
    }

    public function test_an_unassigned_teacher_cannot_create_an_exam(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        [$class] = $this->makeClassWithStudent($year);
        $user = User::factory()->create();
        // Teacher exists but has no assignment for this class/subject.
        Teacher::create(['user_id' => $user->id, 'first_name' => 'A', 'last_name' => 'B', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->set('newExamName', 'Midterm')
            ->call('createExam');

        $this->assertDatabaseMissing('exams', ['name' => 'Midterm']);
    }

    /**
     * Reuses the same QuarterBelongsToActiveYear rule the dashboard's
     * own grade entry uses — a quarter from a non-active year must be
     * rejected when creating an exam too.
     */
    public function test_creating_an_exam_with_a_quarter_outside_the_active_year_is_rejected(): void
    {
        $activeYear = $this->makeYear(true);
        $pastYear = $this->makeYear(false);
        $pastQuarter = Quarter::create(['academic_year_id' => $pastYear->id, 'name' => 'Q1', 'order' => 1]);
        $subject = $this->makeSubject();
        [$class] = $this->makeClassWithStudent($activeYear);
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $subject->id, $activeYear->id);

        Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->set('newExamName', 'Midterm')
            ->set('newExamQuarterId', $pastQuarter->id)
            ->call('createExam')
            ->assertHasErrors(['newExamQuarterId']);

        $this->assertDatabaseMissing('exams', ['name' => 'Midterm']);
    }

    /*
    |--------------------------------------------------------------------------
    | Load-time enforcement
    |--------------------------------------------------------------------------
    */

    public function test_a_user_with_no_linked_teacher_record_cannot_load_exams_or_grades(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        [$class, $student] = $this->makeClassWithStudent($year);
        $exam = $this->makeExam($class->id, $subject->id);
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->call('loadExams');

        $this->assertSame([], $component->get('exams'));

        $component->set('examId', $exam->id)->call('loadStudents');
        $this->assertSame([], $component->get('students'));
    }

    public function test_a_teacher_assigned_to_the_class_but_not_this_subject_cannot_load_grades(): void
    {
        $year = $this->makeYear();
        $assignedSubject = $this->makeSubject();
        $otherSubject = $this->makeSubject();
        [$class, $student] = $this->makeClassWithStudent($year);
        $exam = $this->makeExam($class->id, $otherSubject->id);
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $assignedSubject->id, $year->id);

        $component = Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $otherSubject->id)
            ->set('examId', $exam->id)
            ->call('loadStudents');

        $this->assertSame([], $component->get('students'));
    }

    public function test_an_assignment_from_a_different_academic_year_does_not_grant_grade_access(): void
    {
        $activeYear = $this->makeYear(true);
        $pastYear = $this->makeYear(false);
        $subject = $this->makeSubject();
        [$class, $student] = $this->makeClassWithStudent($activeYear);
        $exam = $this->makeExam($class->id, $subject->id);
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $subject->id, $pastYear->id);

        $component = Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->set('examId', $exam->id)
            ->call('loadStudents');

        $this->assertSame([], $component->get('students'));
    }

    /*
    |--------------------------------------------------------------------------
    | Save-time enforcement
    |--------------------------------------------------------------------------
    */

    public function test_tampering_subject_id_after_a_legitimate_load_blocks_the_save(): void
    {
        $year = $this->makeYear();
        $assignedSubject = $this->makeSubject();
        $foreignSubject = $this->makeSubject();
        [$class, $student] = $this->makeClassWithStudent($year);
        $exam = $this->makeExam($class->id, $assignedSubject->id);
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $assignedSubject->id, $year->id);

        $component = Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $assignedSubject->id)
            ->set('examId', $exam->id)
            ->call('loadStudents')
            ->set("grades.{$student->id}", 95);

        // Tamper: switch subjectId to one this teacher isn't assigned to
        // for this class, then attempt to save.
        $component->set('subjectId', $foreignSubject->id)
            ->call('saveGrades');

        $this->assertDatabaseMissing('student_grades', ['student_id' => $student->id]);
    }

    /**
     * Even when classId/subjectId are authorized, an exam belonging to
     * a different class/subject must be rejected — examId is client
     * state and could be tampered with to point at an unrelated exam.
     */
    public function test_tampering_exam_id_to_an_exam_outside_the_authorized_class_subject_blocks_the_save(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        [$assignedClass, $student] = $this->makeClassWithStudent($year, 'Assigned Student');
        [$foreignClass] = $this->makeClassWithStudent($year, 'Foreign Class Student');
        $foreignExam = $this->makeExam($foreignClass->id, $subject->id);
        $user = User::factory()->create();
        $this->linkTeacher($user, $assignedClass->id, $subject->id, $year->id);

        Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $assignedClass->id)
            ->set('subjectId', $subject->id)
            ->set('examId', $foreignExam->id)
            ->set("grades.{$student->id}", 60)
            ->call('saveGrades');

        $this->assertDatabaseMissing('student_grades', ['student_id' => $student->id]);
    }

    /**
     * Even when classId/subjectId/examId are all authorized, an
     * individual grade entry that targets a student from a different
     * class must be rejected.
     */
    public function test_a_grade_entry_for_a_student_outside_the_authorized_class_is_not_saved(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        [$assignedClass, $assignedStudent] = $this->makeClassWithStudent($year, 'Assigned Student');
        [, $foreignStudent] = $this->makeClassWithStudent($year, 'Foreign Student');
        $exam = $this->makeExam($assignedClass->id, $subject->id);
        $user = User::factory()->create();
        $this->linkTeacher($user, $assignedClass->id, $subject->id, $year->id);

        Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $assignedClass->id)
            ->set('subjectId', $subject->id)
            ->set('examId', $exam->id)
            ->call('loadStudents')
            ->set("grades.{$assignedStudent->id}", 70)
            ->set("grades.{$foreignStudent->id}", 100)
            ->call('saveGrades');

        $this->assertDatabaseHas('student_grades', ['student_id' => $assignedStudent->id, 'score' => 70]);
        $this->assertDatabaseMissing('student_grades', ['student_id' => $foreignStudent->id]);
    }

    public function test_calling_save_directly_without_ever_loading_is_still_denied(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        [$class, $student] = $this->makeClassWithStudent($year);
        $exam = $this->makeExam($class->id, $subject->id);
        $user = User::factory()->create();
        // No teacher linked at all.

        Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->set('examId', $exam->id)
            ->set("grades.{$student->id}", 60)
            ->call('saveGrades');

        $this->assertDatabaseMissing('student_grades', ['student_id' => $student->id]);
    }
}

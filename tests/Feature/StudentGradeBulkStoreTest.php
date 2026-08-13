<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Quarter;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for A3 (docs/IMPLEMENTATION_READINESS_ROADMAP.md):
 * StudentGradeController::bulkStore() used to force-write `score = 0` when a
 * teacher left the score blank but entered a note (e.g. "sick"). A blank
 * score with no note is a legitimate no-op (skip); a blank score WITH a note
 * is now rejected with a validation error instead of silently dropping the
 * note or fabricating a 0 — and the rejection is atomic: no row from the
 * same submission is persisted, even ones that were individually valid.
 */
class StudentGradeBulkStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function makeExamFixture(): Exam
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['stage_id' => $stage->id, 'name' => 'Grade 1']);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A', 'name_ar' => 'الفصل أ']);
        $subject = Subject::create(['code' => 'MATH', 'name_ar' => 'رياضيات']);

        // Item 2: Exam creation now fails closed without a resolvable
        // quarter — this file's own tests are about A3's blank-score/note
        // handling, unrelated to the academic-year lock, so the exam needs
        // a real, active-year quarter just to be creatable at all.
        $year = AcademicYear::create([
            'name' => 'Fixture Year', 'start_date' => '2000-09-01', 'end_date' => '2001-05-31', 'is_active' => true,
        ]);
        $quarter = Quarter::create(['academic_year_id' => $year->id, 'name' => 'Fixture Q', 'order' => 1]);

        // Batch 11 / C3: Exam creation now also requires a matching
        // Curriculum row (year + grade + subject) — unrelated to this
        // file's own concern (blank-score/note handling).
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        return Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);
    }

    protected function postBulkStore(Exam $exam, array $grades)
    {
        // Active + administrative role clears EnsureAdministrativePortalAccess;
        // the student-grades controller has no further permission gate.
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('reception');

        return $this->actingAs($user)->post(route('dashboard.student-grades.bulk.store'), [
            'class_id' => $exam->class_id,
            'subject_id' => $exam->subject_id,
            'exam_id' => $exam->id,
            'grades' => $grades,
        ]);
    }

    public function test_blank_score_and_blank_note_is_skipped_without_error(): void
    {
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student', 'class_id' => $exam->class_id]);

        $response = $this->postBulkStore($exam, [
            $student->id => ['score' => '', 'note' => ''],
        ]);

        $response->assertRedirect(route('dashboard.student-grades.bulk.form'));
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseCount('student_grades', 0);
    }

    public function test_blank_score_with_a_note_is_rejected_with_a_validation_error(): void
    {
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student', 'class_id' => $exam->class_id]);

        $response = $this->postBulkStore($exam, [
            $student->id => ['score' => '', 'note' => 'sick'],
        ]);

        $response->assertSessionHasErrors(["grades.{$student->id}.score"]);
        $this->assertDatabaseCount('student_grades', 0);
    }

    public function test_the_validation_error_message_is_localized(): void
    {
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student', 'class_id' => $exam->class_id]);

        $this->postBulkStore($exam, [
            $student->id => ['score' => '', 'note' => 'sick'],
        ]);

        $errors = session('errors');

        $this->assertSame(
            __('student_grades.score_required_with_note'),
            $errors->first("grades.{$student->id}.score")
        );
    }

    public function test_a_real_score_with_a_note_is_saved_correctly(): void
    {
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student', 'class_id' => $exam->class_id]);

        $response = $this->postBulkStore($exam, [
            $student->id => ['score' => '85', 'note' => 'Good work'],
        ]);

        $response->assertRedirect(route('dashboard.student-grades.bulk.form'));
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('student_grades', [
            'student_id' => $student->id,
            'subject_id' => $exam->subject_id,
            'exam_id' => $exam->id,
            'score' => 85,
            'note' => 'Good work',
        ]);
    }

    public function test_an_explicit_zero_score_is_still_saved_as_zero(): void
    {
        // Confirms the fix distinguishes a real, deliberate 0 from a blank field.
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student', 'class_id' => $exam->class_id]);

        $response = $this->postBulkStore($exam, [
            $student->id => ['score' => '0', 'note' => null],
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('student_grades', [
            'student_id' => $student->id,
            'subject_id' => $exam->subject_id,
            'exam_id' => $exam->id,
            'score' => 0,
        ]);
    }

    public function test_one_invalid_row_rejects_the_entire_batch_and_persists_nothing(): void
    {
        // Atomicity: a batch of 3 students where only the middle one is
        // invalid (note without score) must save NONE of them, including
        // the two rows that were individually valid.
        $exam = $this->makeExamFixture();
        $validStudentA = Student::create(['name' => 'Student A', 'class_id' => $exam->class_id]);
        $invalidStudent = Student::create(['name' => 'Student B', 'class_id' => $exam->class_id]);
        $validStudentC = Student::create(['name' => 'Student C', 'class_id' => $exam->class_id]);

        $response = $this->postBulkStore($exam, [
            $validStudentA->id => ['score' => '90', 'note' => null],
            $invalidStudent->id => ['score' => '', 'note' => 'sick'],
            $validStudentC->id => ['score' => '75', 'note' => null],
        ]);

        $response->assertSessionHasErrors(["grades.{$invalidStudent->id}.score"]);
        $this->assertDatabaseCount('student_grades', 0);
        $this->assertDatabaseMissing('student_grades', ['student_id' => $validStudentA->id]);
        $this->assertDatabaseMissing('student_grades', ['student_id' => $validStudentC->id]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Quarter;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B9 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, narrow slice): a submitted
 * quarter_id must belong to the currently active academic year. Explicit
 * policy: a null-year quarter fails, and "no active year at all" fails with
 * its own distinct, Russian-first message rather than skipping the check.
 */
class StudentGradeQuarterValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeExamFixture(): Exam
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['stage_id' => $stage->id, 'name' => 'Grade 1']);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A', 'name_ar' => 'الفصل أ']);
        $subject = Subject::create(['code' => 'MATH', 'name_ar' => 'رياضيات']);

        return Exam::create(['name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id]);
    }

    protected function postGrade(Exam $exam, Student $student, $quarterId)
    {
        $user = User::factory()->create();

        return $this->actingAs($user)->post(route('dashboard.student-grades.store'), [
            'student_id' => $student->id,
            'subject_id' => $exam->subject_id,
            'exam_id' => $exam->id,
            'quarter_id' => $quarterId,
            'score' => 90,
        ]);
    }

    public function test_a_valid_quarter_in_the_active_year_passes(): void
    {
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student']);
        $activeYear = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $quarter = Quarter::create(['academic_year_id' => $activeYear->id, 'name' => 'Q1', 'order' => 1]);

        $response = $this->postGrade($exam, $student, $quarter->id);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('student_grades', [
            'student_id' => $student->id,
            'quarter_id' => $quarter->id,
        ]);
    }

    public function test_a_quarter_from_another_year_fails(): void
    {
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student']);

        $activeYear = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $otherYear = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $quarterFromOtherYear = Quarter::create(['academic_year_id' => $otherYear->id, 'name' => 'Q4', 'order' => 4]);

        $response = $this->postGrade($exam, $student, $quarterFromOtherYear->id);

        $response->assertSessionHasErrors(['quarter_id' => __('student_grades.quarter_not_in_active_year')]);
        $this->assertDatabaseCount('student_grades', 0);
    }

    // A quarter with a null academic_year_id can no longer be constructed
    // at all as of Item 3 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, B1 —
    // quarters.academic_year_id is now required), so the test that used to
    // cover that branch of QuarterBelongsToActiveYear was removed rather
    // than rewritten — its premise no longer exists.

    public function test_no_active_academic_year_fails_with_a_distinct_message(): void
    {
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student']);

        $inactiveYear = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => false,
        ]);
        $quarter = Quarter::create(['academic_year_id' => $inactiveYear->id, 'name' => 'Q1', 'order' => 1]);

        $response = $this->postGrade($exam, $student, $quarter->id);

        $response->assertSessionHasErrors(['quarter_id' => __('student_grades.no_active_academic_year')]);
        $this->assertDatabaseCount('student_grades', 0);
    }

    public function test_validation_messages_are_localized_in_all_three_locales(): void
    {
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student']);

        $activeYear = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $otherYear = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $quarterFromOtherYear = Quarter::create(['academic_year_id' => $otherYear->id, 'name' => 'Q4', 'order' => 4]);

        foreach (['ru', 'en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $message = __('student_grades.quarter_not_in_active_year');
            $this->assertNotSame('student_grades.quarter_not_in_active_year', $message);

            $response = $this->postGrade($exam, $student, $quarterFromOtherYear->id);
            $response->assertSessionHasErrors(['quarter_id' => $message]);
        }
    }

    public function test_a_nonexistent_quarter_id_is_safely_rejected(): void
    {
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student']);

        AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $response = $this->postGrade($exam, $student, 999999);

        $response->assertSessionHasErrors('quarter_id');
        $this->assertDatabaseCount('student_grades', 0);
    }

    public function test_a_malformed_quarter_id_is_safely_rejected_without_a_server_error(): void
    {
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student']);

        AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $response = $this->postGrade($exam, $student, 'not-a-valid-id');

        $response->assertSessionHasErrors('quarter_id');
        $this->assertDatabaseCount('student_grades', 0);
    }

    public function test_guest_is_redirected_to_login_rather_than_reaching_validation(): void
    {
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student']);

        $response = $this->post(route('dashboard.student-grades.store'), [
            'student_id' => $student->id,
            'subject_id' => $exam->subject_id,
            'exam_id' => $exam->id,
            'quarter_id' => 1,
            'score' => 90,
        ]);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('student_grades', 0);
    }

    public function test_no_quarter_selected_still_passes_unaffected(): void
    {
        // Regression: quarter_id remains fully optional for callers that
        // don't use quarters at all (unaffected by this validation rule).
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student']);

        AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $response = $this->postGrade($exam, $student, null);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('student_grades', [
            'student_id' => $student->id,
            'quarter_id' => null,
        ]);
    }
}

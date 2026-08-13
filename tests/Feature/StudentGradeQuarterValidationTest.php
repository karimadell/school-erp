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
use App\Support\AcademicYearLock;
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

        // Item 2: Exam creation now fails closed without a resolvable
        // quarter. This fixture year/quarter exists only to satisfy that —
        // this file's own tests submit quarter_id directly on the grade
        // (which takes precedence in StudentGrade::resolveAcademicYear()),
        // independent of whatever active/inactive year scenario each test
        // sets up afterward.
        $fixtureYear = AcademicYear::create([
            'name' => 'Fixture Year', 'start_date' => '2000-09-01', 'end_date' => '2001-05-31', 'is_active' => true,
        ]);
        $fixtureQuarter = Quarter::create(['academic_year_id' => $fixtureYear->id, 'name' => 'Fixture Q', 'order' => 1]);

        // Batch 11 / C3: Exam creation now also requires a matching
        // Curriculum row (year + grade + subject) — unrelated to this
        // file's own concern (submitted quarter_id validation).
        Curriculum::create([
            'academic_year_id' => $fixtureYear->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        return Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $fixtureQuarter->id,
        ]);
    }

    protected function postGrade(Exam $exam, Student $student, $quarterId)
    {
        // Active + administrative role clears EnsureAdministrativePortalAccess;
        // the student-grades controller has no further permission gate.
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('reception');

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
        // The grade's own quarter_id (submitted below) takes precedence
        // over the exam's for resolveAcademicYear() — so the curriculum
        // check needs a row for THIS year/grade/subject, not the fixture
        // exam's own year.
        Curriculum::create([
            'academic_year_id' => $activeYear->id, 'grade_id' => $exam->grade_id, 'subject_id' => $exam->subject_id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

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
        // Item 2: creating a Quarter under an already-inactive year is
        // itself now locked — this fixture needs the explicit, narrow
        // bypass since it's testing THIS file's own concern (a submitted
        // quarter from a non-active year is rejected), not the AY lock.
        $quarterFromOtherYear = AcademicYearLock::withoutLock(
            fn () => Quarter::create(['academic_year_id' => $otherYear->id, 'name' => 'Q4', 'order' => 4])
        );

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

        // makeExamFixture() creates its own active "Fixture Year" (needed
        // just to satisfy Exam's own creation-time lock) — this test's
        // whole point is "no active year exists anywhere", so deactivate
        // it via a direct query update (bypassing AcademicYear::save()'s
        // override — nothing else needs deactivating here).
        AcademicYear::where('is_active', true)->update(['is_active' => false]);

        $inactiveYear = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => false,
        ]);
        $quarter = AcademicYearLock::withoutLock(
            fn () => Quarter::create(['academic_year_id' => $inactiveYear->id, 'name' => 'Q1', 'order' => 1])
        );

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
        $quarterFromOtherYear = AcademicYearLock::withoutLock(
            fn () => Quarter::create(['academic_year_id' => $otherYear->id, 'name' => 'Q4', 'order' => 4])
        );

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

    public function test_no_quarter_selected_now_fails_closed(): void
    {
        // Item 2, approved policy decision 1: this used to document that
        // quarter_id was fully optional and a no-quarter grade passed
        // unaffected. That guarantee is deliberately reversed — a
        // StudentGrade with no resolvable quarter/academic year (neither
        // its own quarter_id nor its exam's) now fails closed rather than
        // being silently treated as "unscoped/exempt".
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student']);

        // Activating a new year deactivates makeExamFixture()'s own
        // "Fixture Year", so the exam's quarter is now historical too —
        // there is no active-year path left for this grade to resolve
        // through at all.
        AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $response = $this->postGrade($exam, $student, null);

        $response->assertSessionHasErrors();
        $this->assertDatabaseCount('student_grades', 0);
    }
}

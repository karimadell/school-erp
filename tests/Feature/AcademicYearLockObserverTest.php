<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AcademicYearUnlock;
use App\Models\Attendance;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\LessonJournalEntry;
use App\Models\Period;
use App\Models\Quarter;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\StudentServiceSubscription;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Item 2 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, B9): historical
 * academic-year write locking. Proves the central AcademicYearLockObserver
 * mechanism directly against every locked model — each is blocked when its
 * resolved year is historical-and-locked, allowed when active, allowed
 * when historical-but-currently-unlocked, and (for Exam/StudentGrade only)
 * blocked when no year can be resolved at all.
 */
class AcademicYearLockObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function makeYear(bool $active): AcademicYear
    {
        return AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2020-09-01', 'end_date' => '2021-05-31', 'is_active' => $active,
        ]);
    }

    protected function unlock(AcademicYear $year): AcademicYearUnlock
    {
        return AcademicYearUnlock::create([
            'academic_year_id' => $year->id,
            'reason' => 'Test unlock',
            'unlocked_by' => null,
            'expires_at' => now()->addHour(),
        ]);
    }

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);

        return SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    protected function makeSubject(): Subject
    {
        return Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    protected function makeQuarter(AcademicYear $year): Quarter
    {
        return Quarter::create(['academic_year_id' => $year->id, 'name' => 'Q-' . uniqid(), 'order' => 1]);
    }

    protected function makeEnrollment(AcademicYear $year, SchoolClass $class): Enrollment
    {
        $student = Student::forceCreate(['name' => 'Student ' . uniqid()]);

        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'stage_id' => $class->grade->stage_id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Enrollment
    |--------------------------------------------------------------------------
    */

    public function test_enrollment_create_is_blocked_for_a_locked_historical_year(): void
    {
        $year = $this->makeYear(false);
        $class = $this->makeClass();

        $this->expectException(ValidationException::class);
        $this->makeEnrollment($year, $class);
    }

    public function test_enrollment_create_succeeds_for_the_active_year(): void
    {
        $year = $this->makeYear(true);
        $class = $this->makeClass();

        $enrollment = $this->makeEnrollment($year, $class);

        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);
    }

    public function test_enrollment_create_succeeds_for_a_historical_but_unlocked_year(): void
    {
        $year = $this->makeYear(false);
        $this->unlock($year);
        $class = $this->makeClass();

        $enrollment = $this->makeEnrollment($year, $class);

        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);
    }

    public function test_enrollment_update_is_blocked_once_its_year_becomes_historical(): void
    {
        $year = $this->makeYear(true);
        $class = $this->makeClass();
        $enrollment = $this->makeEnrollment($year, $class);

        $year->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $enrollment->update(['status' => 'transferred']);
    }

    public function test_enrollment_delete_is_blocked_once_its_year_becomes_historical(): void
    {
        $year = $this->makeYear(true);
        $class = $this->makeClass();
        $enrollment = $this->makeEnrollment($year, $class);

        $year->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $enrollment->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Attendance (via Enrollment.academic_year_id)
    |--------------------------------------------------------------------------
    */

    public function test_attendance_create_is_blocked_for_an_enrollment_in_a_locked_year(): void
    {
        $year = $this->makeYear(true);
        $class = $this->makeClass();
        $enrollment = $this->makeEnrollment($year, $class);
        $year->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        Attendance::create([
            'enrollment_id' => $enrollment->id,
            'date' => now()->toDateString(),
            'type' => 'daily',
            'status' => 'present',
            'attendance_key' => Attendance::buildAttendanceKey('daily', $enrollment->id, now()->toDateString()),
        ]);
    }

    public function test_attendance_create_succeeds_for_an_enrollment_in_the_active_year(): void
    {
        $year = $this->makeYear(true);
        $class = $this->makeClass();
        $enrollment = $this->makeEnrollment($year, $class);

        $attendance = Attendance::create([
            'enrollment_id' => $enrollment->id,
            'date' => now()->toDateString(),
            'type' => 'daily',
            'status' => 'present',
            'attendance_key' => Attendance::buildAttendanceKey('daily', $enrollment->id, now()->toDateString()),
        ]);

        $this->assertDatabaseHas('attendances', ['id' => $attendance->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Quarter
    |--------------------------------------------------------------------------
    */

    public function test_quarter_create_is_blocked_for_a_locked_historical_year(): void
    {
        $year = $this->makeYear(false);

        $this->expectException(ValidationException::class);
        $this->makeQuarter($year);
    }

    public function test_quarter_create_succeeds_for_the_active_year(): void
    {
        $year = $this->makeYear(true);

        $quarter = $this->makeQuarter($year);

        $this->assertDatabaseHas('quarters', ['id' => $quarter->id]);
    }

    public function test_quarter_create_succeeds_for_a_historical_but_unlocked_year(): void
    {
        $year = $this->makeYear(false);
        $this->unlock($year);

        $quarter = $this->makeQuarter($year);

        $this->assertDatabaseHas('quarters', ['id' => $quarter->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Exam — fails closed when no quarter is resolvable (approved decision)
    |--------------------------------------------------------------------------
    */

    public function test_exam_create_is_blocked_with_no_quarter_at_all(): void
    {
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        $this->expectException(ValidationException::class);
        Exam::create(['name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id]);
    }

    public function test_exam_create_is_blocked_when_its_quarter_belongs_to_a_locked_year(): void
    {
        $year = $this->makeYear(false);
        $quarter = $this->unlockedQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        $this->expectException(ValidationException::class);
        Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);
    }

    public function test_exam_create_succeeds_with_a_quarter_in_the_active_year(): void
    {
        $year = $this->makeYear(true);
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $exam = Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);

        $this->assertDatabaseHas('exams', ['id' => $exam->id]);
    }

    /** Creates a Quarter under a locked year via the narrow bypass — fixture only. */
    protected function unlockedQuarter(AcademicYear $year): Quarter
    {
        return \App\Support\AcademicYearLock::withoutLock(fn () => $this->makeQuarter($year));
    }

    /*
    |--------------------------------------------------------------------------
    | StudentGrade — fails closed when no quarter is resolvable (own or exam's)
    |--------------------------------------------------------------------------
    */

    public function test_student_grade_create_is_blocked_with_no_resolvable_quarter_at_all(): void
    {
        $subject = $this->makeSubject();
        $student = Student::forceCreate(['name' => 'S']);

        // exam_id left null AND quarter_id left null — genuinely unresolvable.
        $this->expectException(ValidationException::class);
        StudentGrade::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'exam_id' => null,
            'quarter_id' => null,
            'score' => 90,
        ]);
    }

    public function test_student_grade_create_succeeds_via_its_own_quarter_in_the_active_year(): void
    {
        $year = $this->makeYear(true);
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        $student = Student::forceCreate(['name' => 'S', 'class_id' => $class->id]);

        $grade = StudentGrade::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'quarter_id' => $quarter->id,
            'score' => 90,
        ]);

        $this->assertDatabaseHas('student_grades', ['id' => $grade->id]);
    }

    public function test_student_grade_create_is_blocked_when_its_quarter_belongs_to_a_locked_year(): void
    {
        $year = $this->makeYear(false);
        $quarter = $this->unlockedQuarter($year);
        $subject = $this->makeSubject();
        $student = Student::forceCreate(['name' => 'S']);

        $this->expectException(ValidationException::class);
        StudentGrade::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'quarter_id' => $quarter->id,
            'score' => 90,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | TeacherAssignment
    |--------------------------------------------------------------------------
    */

    public function test_teacher_assignment_create_is_blocked_for_a_locked_historical_year(): void
    {
        $year = $this->makeYear(false);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id, 'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);
    }

    public function test_teacher_assignment_create_succeeds_for_the_active_year(): void
    {
        $year = $this->makeYear(true);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B', 'is_active' => true]);

        $assignment = TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id, 'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $this->assertDatabaseHas('teacher_assignments', ['id' => $assignment->id]);
    }

    public function test_teacher_assignment_create_succeeds_for_a_historical_but_unlocked_year(): void
    {
        $year = $this->makeYear(false);
        $this->unlock($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B', 'is_active' => true]);

        $assignment = TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id, 'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $this->assertDatabaseHas('teacher_assignments', ['id' => $assignment->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | LessonJournalEntry
    |--------------------------------------------------------------------------
    */

    public function test_lesson_journal_entry_create_is_blocked_for_a_locked_historical_year(): void
    {
        $year = $this->makeYear(false);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        LessonJournalEntry::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id, 'subject_id' => $subject->id,
            'academic_year_id' => $year->id, 'date' => now()->toDateString(), 'title' => 'X',
        ]);
    }

    public function test_lesson_journal_entry_create_succeeds_for_the_active_year(): void
    {
        $year = $this->makeYear(true);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B', 'is_active' => true]);

        $entry = LessonJournalEntry::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id, 'subject_id' => $subject->id,
            'academic_year_id' => $year->id, 'date' => now()->toDateString(), 'title' => 'X',
        ]);

        $this->assertDatabaseHas('lesson_journal_entries', ['id' => $entry->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | StudentServiceSubscription (via Enrollment.academic_year_id)
    |--------------------------------------------------------------------------
    */

    public function test_student_service_subscription_create_is_blocked_for_an_enrollment_in_a_locked_year(): void
    {
        $year = $this->makeYear(true);
        $class = $this->makeClass();
        $enrollment = $this->makeEnrollment($year, $class);
        $year->update(['is_active' => false]);
        $fee = Fee::create(['name_ru' => 'Fee', 'name_ar' => 'a', 'type' => 'service', 'category' => 'other', 'amount' => 100]);

        $this->expectException(ValidationException::class);
        StudentServiceSubscription::create([
            'enrollment_id' => $enrollment->id, 'fee_id' => $fee->id, 'start_date' => now()->toDateString(),
            'quantity' => 1, 'status' => StudentServiceSubscription::STATUS_ACTIVE,
        ]);
    }

    public function test_student_service_subscription_create_succeeds_for_an_enrollment_in_the_active_year(): void
    {
        $year = $this->makeYear(true);
        $class = $this->makeClass();
        $enrollment = $this->makeEnrollment($year, $class);
        $fee = Fee::create(['name_ru' => 'Fee', 'name_ar' => 'a', 'type' => 'service', 'category' => 'other', 'amount' => 100]);

        $subscription = StudentServiceSubscription::create([
            'enrollment_id' => $enrollment->id, 'fee_id' => $fee->id, 'start_date' => now()->toDateString(),
            'quantity' => 1, 'status' => StudentServiceSubscription::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('student_service_subscriptions', ['id' => $subscription->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | The bypass mechanism itself
    |--------------------------------------------------------------------------
    */

    public function test_withoutlock_allows_a_write_that_would_otherwise_be_blocked(): void
    {
        $year = $this->makeYear(false);

        $quarter = \App\Support\AcademicYearLock::withoutLock(fn () => $this->makeQuarter($year));

        $this->assertDatabaseHas('quarters', ['id' => $quarter->id]);
    }

    public function test_withoutlock_bypass_does_not_leak_to_a_later_unrelated_write(): void
    {
        $lockedYear = $this->makeYear(false);
        \App\Support\AcademicYearLock::withoutLock(fn () => $this->makeQuarter($lockedYear));

        // The bypass must not still be active after the closure returns.
        $anotherLockedYear = $this->makeYear(false);
        $this->expectException(ValidationException::class);
        $this->makeQuarter($anotherLockedYear);
    }
}

<?php

namespace Tests\Feature\Teacher;

use App\Filament\Teacher\Pages\TeacherAttendance;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Support\AcademicYearLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeacherAttendanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * $sharedYear lets multiple enrollments in one test share the same
     * academic year — AcademicYear::save() deactivates every other
     * active year as soon as a new active one is saved, so creating a
     * second year via a bare call would silently flip which year is
     * "current" out from under an already-created assignment.
     */
    protected function makeEnrollment(string $studentName = 'Test Student', ?AcademicYear $sharedYear = null): Enrollment
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A',
            'name_ar' => 'فصل A',
            'name_ru' => 'Класс A',
        ]);
        $student = Student::create(['name' => $studentName]);
        $year = $sharedYear ?? AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'stage_id' => $stage->id,
            'grade_id' => $grade->id,
            'class_id' => $class->id,
            'status' => 'active',
        ]);
    }

    /**
     * Batch 8: links $user to a Teacher record and gives that teacher a
     * real TeacherAssignment for the enrollment's class, for the given
     * (or a freshly created) academic year. Every existing test in this
     * file needs this now that TeacherAttendance enforces record-level
     * scoping — without it, currentTeacher() resolves to null and every
     * load/save is denied.
     */
    protected function linkTeacher(User $user, Enrollment $enrollment, ?int $academicYearId = null): Teacher
    {
        $teacher = Teacher::create([
            'user_id' => $user->id,
            'first_name' => 'Anna',
            'last_name' => 'Ivanova',
            'is_active' => true,
        ]);

        $subject = Subject::create(['code' => 'SUBJ-' . uniqid(), 'name_ar' => 'مادة', 'name_ru' => 'Предмет']);

        TeacherAssignment::create([
            'teacher_id' => $teacher->id,
            'class_id' => $enrollment->class_id,
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYearId ?? $enrollment->academic_year_id,
        ]);

        return $teacher;
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-existing behavior (now exercised through an assigned teacher)
    |--------------------------------------------------------------------------
    */

    /**
     * Regression test: TeacherAttendance::saveAttendance() previously wrote
     * to attendances.student_id, a column that does not exist. attendances
     * is keyed via enrollment_id.
     */
    public function test_attendance_can_be_saved_for_a_single_student(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();
        $this->linkTeacher($user, $enrollment);

        Livewire::actingAs($user)
            ->test(TeacherAttendance::class)
            ->set('classId', $enrollment->class_id)
            ->call('loadStudents')
            ->set("attendance.{$enrollment->id}", 'absent')
            ->call('saveAttendance')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attendances', [
            'enrollment_id' => $enrollment->id,
            'status' => 'absent',
            'type' => 'daily',
        ]);
    }

    public function test_attendance_can_be_saved_for_multiple_students(): void
    {
        $user = User::factory()->create();
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A',
            'name_ar' => 'فصل A',
            'name_ru' => 'Класс A',
        ]);

        $studentA = Student::create(['name' => 'Student A']);
        $studentB = Student::create(['name' => 'Student B']);
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $enrollmentA = Enrollment::create([
            'student_id' => $studentA->id, 'academic_year_id' => $year->id, 'stage_id' => $stage->id,
            'grade_id' => $grade->id, 'class_id' => $class->id, 'status' => 'active',
        ]);
        $enrollmentB = Enrollment::create([
            'student_id' => $studentB->id, 'academic_year_id' => $year->id, 'stage_id' => $stage->id,
            'grade_id' => $grade->id, 'class_id' => $class->id, 'status' => 'active',
        ]);

        $this->linkTeacher($user, $enrollmentA);

        Livewire::actingAs($user)
            ->test(TeacherAttendance::class)
            ->set('classId', $class->id)
            ->call('loadStudents')
            ->set("attendance.{$enrollmentA->id}", 'present')
            ->set("attendance.{$enrollmentB->id}", 'late')
            ->call('saveAttendance')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attendances', ['enrollment_id' => $enrollmentA->id, 'status' => 'present']);
        $this->assertDatabaseHas('attendances', ['enrollment_id' => $enrollmentB->id, 'status' => 'late']);
    }

    /**
     * Regression test: attendance_key is NOT NULL/unique with no default
     * and was never generated by the teacher panel before this fix.
     */
    public function test_attendance_key_is_generated_correctly(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();
        $this->linkTeacher($user, $enrollment);
        $today = now()->toDateString();

        Livewire::actingAs($user)
            ->test(TeacherAttendance::class)
            ->set('classId', $enrollment->class_id)
            ->call('loadStudents')
            ->set("attendance.{$enrollment->id}", 'present')
            ->call('saveAttendance');

        $this->assertDatabaseHas('attendances', [
            'attendance_key' => Attendance::buildAttendanceKey('daily', $enrollment->id, $today),
        ]);
    }

    public function test_saving_twice_updates_rather_than_duplicates(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();
        $this->linkTeacher($user, $enrollment);

        $component = Livewire::actingAs($user)
            ->test(TeacherAttendance::class)
            ->set('classId', $enrollment->class_id)
            ->call('loadStudents');

        $component->set("attendance.{$enrollment->id}", 'present')->call('saveAttendance');
        $component->set("attendance.{$enrollment->id}", 'late')->call('saveAttendance');

        $this->assertSame(1, Attendance::where('enrollment_id', $enrollment->id)->count());
        $this->assertDatabaseHas('attendances', ['enrollment_id' => $enrollment->id, 'status' => 'late']);
    }

    /**
     * All statuses the dashboard workflow supports, including the
     * previously-missing "excused" option, must be persistable via the
     * teacher panel too.
     */
    public function test_all_supported_statuses_can_be_saved(): void
    {
        $user = User::factory()->create();
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $teacher = null;

        foreach (['present', 'absent', 'late', 'excused'] as $status) {
            $enrollment = $this->makeEnrollment("Student {$status}", $year);

            if (! $teacher) {
                $teacher = $this->linkTeacher($user, $enrollment, $year->id);
            } else {
                TeacherAssignment::create([
                    'teacher_id' => $teacher->id,
                    'class_id' => $enrollment->class_id,
                    'subject_id' => Subject::first()?->id ?? Subject::create(['code' => 'X', 'name_ar' => 'a', 'name_ru' => 'a'])->id,
                    'academic_year_id' => $year->id,
                ]);
            }

            Livewire::actingAs($user)
                ->test(TeacherAttendance::class)
                ->set('classId', $enrollment->class_id)
                ->call('loadStudents')
                ->set("attendance.{$enrollment->id}", $status)
                ->call('saveAttendance')
                ->assertHasNoErrors();

            $this->assertDatabaseHas('attendances', [
                'enrollment_id' => $enrollment->id,
                'status' => $status,
            ]);
        }
    }

    /**
     * Regression test: saveAttendance() persisted whatever status value
     * the client sent, with no validation against the supported set.
     */
    public function test_invalid_status_is_rejected(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();
        $this->linkTeacher($user, $enrollment);

        Livewire::actingAs($user)
            ->test(TeacherAttendance::class)
            ->set('classId', $enrollment->class_id)
            ->call('loadStudents')
            ->set("attendance.{$enrollment->id}", 'not-a-real-status')
            ->call('saveAttendance')
            ->assertHasErrors(["attendance.{$enrollment->id}" => 'in']);

        $this->assertDatabaseMissing('attendances', ['enrollment_id' => $enrollment->id]);
    }

    /**
     * Regression test: teacher-attendance.blade.php was 100% hardcoded
     * Russian text with zero __() calls, so it always rendered Russian
     * regardless of the active locale.
     */
    public function test_page_renders_without_hardcoded_russian_text_in_all_locales(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();
        $this->linkTeacher($user, $enrollment);

        foreach (['ru', 'en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $component = Livewire::actingAs($user)
                ->test(TeacherAttendance::class)
                ->set('classId', $enrollment->class_id)
                ->call('loadStudents');

            // Not a plain 'attendance.' check: wire:model="attendance.{id}"
            // legitimately contains that substring for this component's own
            // $attendance property, so check for specific untranslated key
            // leaks instead.
            $component->assertDontSee('attendance.select_class', false);
            $component->assertDontSee('attendance.load', false);
            $component->assertDontSee('attendance.title', false);
            $component->assertDontSee('attendance.student', false);
            $component->assertDontSee('attendance.status', false);
            $component->assertDontSee('attendance.present', false);
            $component->assertDontSee('attendance.save', false);

            if ($locale !== 'ru') {
                $component->assertDontSee('Выберите класс');
                $component->assertDontSee('Загрузить');
                $component->assertDontSee('Посещаемость');
                $component->assertDontSee('Присутствует');
                $component->assertDontSee('Отсутствует');
                $component->assertDontSee('Опоздал');
                $component->assertDontSee('Освобождён');
                $component->assertDontSee('Сохранить');
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Batch 8: record-level access scoping — load-time enforcement
    |--------------------------------------------------------------------------
    */

    public function test_a_user_with_no_linked_teacher_record_cannot_load_any_class(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();
        // No Teacher record is linked to $user at all.

        Livewire::actingAs($user)
            ->test(TeacherAttendance::class)
            ->set('classId', $enrollment->class_id)
            ->call('loadStudents');

        $this->assertDatabaseMissing('attendances', ['enrollment_id' => $enrollment->id]);
    }

    public function test_a_teacher_not_assigned_to_the_class_cannot_load_its_students(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();

        // Teacher exists and is assigned somewhere, but not to this class.
        $otherEnrollment = $this->makeEnrollment('Unrelated Student');
        $this->linkTeacher($user, $otherEnrollment);

        $component = Livewire::actingAs($user)
            ->test(TeacherAttendance::class)
            ->set('classId', $enrollment->class_id)
            ->call('loadStudents');

        $this->assertSame([], $component->get('students'));
    }

    /**
     * Year scoping: an assignment that exists only for a non-active
     * academic year must not grant access to the current year's class.
     */
    public function test_an_assignment_from_a_different_academic_year_does_not_grant_access(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();

        $pastYear = AcademicYear::create([
            'name' => '2024 / 2025', 'start_date' => '2024-09-01', 'end_date' => '2025-05-31', 'is_active' => false,
        ]);
        AcademicYearLock::withoutLock(fn () => $this->linkTeacher($user, $enrollment, $pastYear->id));

        $component = Livewire::actingAs($user)
            ->test(TeacherAttendance::class)
            ->set('classId', $enrollment->class_id)
            ->call('loadStudents');

        $this->assertSame([], $component->get('students'));
    }

    /*
    |--------------------------------------------------------------------------
    | Batch 8: record-level access scoping — save-time enforcement
    |--------------------------------------------------------------------------
    */

    /**
     * The class must be re-authorized at save time, not merely trusted
     * because a prior loadStudents() call happened to succeed — classId
     * is client-controlled Livewire state and can be changed between the
     * two calls.
     */
    public function test_tampering_class_id_after_a_legitimate_load_blocks_the_save(): void
    {
        $user = User::factory()->create();
        $assignedEnrollment = $this->makeEnrollment('Assigned Student');
        $this->linkTeacher($user, $assignedEnrollment);

        $foreignEnrollment = $this->makeEnrollment('Foreign Student');

        $component = Livewire::actingAs($user)
            ->test(TeacherAttendance::class)
            ->set('classId', $assignedEnrollment->class_id)
            ->call('loadStudents')
            ->set("attendance.{$assignedEnrollment->id}", 'present');

        // Tamper: switch classId to a class this teacher is not assigned
        // to, then attempt to save.
        $component->set('classId', $foreignEnrollment->class_id)
            ->call('saveAttendance');

        $this->assertDatabaseMissing('attendances', ['enrollment_id' => $assignedEnrollment->id]);
    }

    /**
     * Even when classId itself is authorized, an individual attendance
     * entry that targets an enrollment from a different class must be
     * rejected — otherwise a tampered payload could smuggle in writes for
     * unrelated classes while classId passes the outer check.
     */
    public function test_an_attendance_entry_for_an_enrollment_outside_the_authorized_class_is_not_saved(): void
    {
        $user = User::factory()->create();
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $assignedEnrollment = $this->makeEnrollment('Assigned Student', $year);
        $this->linkTeacher($user, $assignedEnrollment, $year->id);

        $foreignEnrollment = $this->makeEnrollment('Foreign Student', $year);

        Livewire::actingAs($user)
            ->test(TeacherAttendance::class)
            ->set('classId', $assignedEnrollment->class_id)
            ->call('loadStudents')
            ->set("attendance.{$assignedEnrollment->id}", 'present')
            ->set("attendance.{$foreignEnrollment->id}", 'absent')
            ->call('saveAttendance');

        $this->assertDatabaseHas('attendances', ['enrollment_id' => $assignedEnrollment->id, 'status' => 'present']);
        $this->assertDatabaseMissing('attendances', ['enrollment_id' => $foreignEnrollment->id]);
    }

    public function test_calling_save_directly_without_ever_loading_is_still_denied(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();
        // No teacher linked at all.

        Livewire::actingAs($user)
            ->test(TeacherAttendance::class)
            ->set('classId', $enrollment->class_id)
            ->set("attendance.{$enrollment->id}", 'present')
            ->call('saveAttendance');

        $this->assertDatabaseMissing('attendances', ['enrollment_id' => $enrollment->id]);
    }
}

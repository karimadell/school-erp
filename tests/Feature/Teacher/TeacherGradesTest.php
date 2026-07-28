<?php

namespace Tests\Feature\Teacher;

use App\Filament\Teacher\Pages\TeacherGrades;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batch 8: TeacherGrades previously loaded/saved grades for any
 * class+subject combination with zero ownership check ($this->classId
 * and $this->subjectId were unguarded Livewire state). These tests prove
 * both load-time and save-time enforcement against TeacherAssignment.
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
    | Assigned teacher: positive path
    |--------------------------------------------------------------------------
    */

    public function test_an_assigned_teacher_can_load_and_save_grades(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        [$class, $student] = $this->makeClassWithStudent($year);
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $subject->id, $year->id);

        Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->call('loadStudents')
            ->set("grades.{$student->id}", 87)
            ->call('saveGrades');

        $this->assertDatabaseHas('student_grades', [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'score' => 87,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Load-time enforcement
    |--------------------------------------------------------------------------
    */

    public function test_a_user_with_no_linked_teacher_record_cannot_load_grades(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        [$class, $student] = $this->makeClassWithStudent($year);
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->call('loadStudents');

        $this->assertSame([], $component->get('students'));
    }

    public function test_a_teacher_assigned_to_the_class_but_not_this_subject_cannot_load_grades(): void
    {
        $year = $this->makeYear();
        $assignedSubject = $this->makeSubject();
        $otherSubject = $this->makeSubject();
        [$class, $student] = $this->makeClassWithStudent($year);
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $assignedSubject->id, $year->id);

        $component = Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $otherSubject->id)
            ->call('loadStudents');

        $this->assertSame([], $component->get('students'));
    }

    public function test_a_teacher_assigned_to_the_subject_but_a_different_class_cannot_load_grades(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        [$assignedClass] = $this->makeClassWithStudent($year, 'Assigned Class Student');
        [$otherClass, $otherStudent] = $this->makeClassWithStudent($year, 'Other Class Student');
        $user = User::factory()->create();
        $this->linkTeacher($user, $assignedClass->id, $subject->id, $year->id);

        $component = Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $otherClass->id)
            ->set('subjectId', $subject->id)
            ->call('loadStudents');

        $this->assertSame([], $component->get('students'));
    }

    public function test_an_assignment_from_a_different_academic_year_does_not_grant_grade_access(): void
    {
        $activeYear = $this->makeYear(true);
        $pastYear = $this->makeYear(false);
        $subject = $this->makeSubject();
        [$class, $student] = $this->makeClassWithStudent($activeYear);
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $subject->id, $pastYear->id);

        $component = Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
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
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $assignedSubject->id, $year->id);

        $component = Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $assignedSubject->id)
            ->call('loadStudents')
            ->set("grades.{$student->id}", 95);

        // Tamper: switch subjectId to one this teacher isn't assigned to
        // for this class, then attempt to save.
        $component->set('subjectId', $foreignSubject->id)
            ->call('saveGrades');

        $this->assertDatabaseMissing('student_grades', [
            'student_id' => $student->id,
            'subject_id' => $foreignSubject->id,
        ]);
        $this->assertDatabaseMissing('student_grades', [
            'student_id' => $student->id,
            'subject_id' => $assignedSubject->id,
        ]);
    }

    /**
     * Even when classId/subjectId are authorized, an individual grade
     * entry that targets a student from a different class must be
     * rejected — otherwise a tampered payload could smuggle in grade
     * writes for unrelated students while classId/subjectId pass the
     * outer check.
     */
    public function test_a_grade_entry_for_a_student_outside_the_authorized_class_is_not_saved(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        [$assignedClass, $assignedStudent] = $this->makeClassWithStudent($year, 'Assigned Student');
        [, $foreignStudent] = $this->makeClassWithStudent($year, 'Foreign Student');
        $user = User::factory()->create();
        $this->linkTeacher($user, $assignedClass->id, $subject->id, $year->id);

        Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $assignedClass->id)
            ->set('subjectId', $subject->id)
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
        $user = User::factory()->create();
        // No teacher linked at all.

        Livewire::actingAs($user)
            ->test(TeacherGrades::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->set("grades.{$student->id}", 60)
            ->call('saveGrades');

        $this->assertDatabaseMissing('student_grades', ['student_id' => $student->id]);
    }
}

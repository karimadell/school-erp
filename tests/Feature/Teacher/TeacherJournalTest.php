<?php

namespace Tests\Feature\Teacher;

use App\Filament\Teacher\Pages\TeacherJournal;
use App\Models\AcademicYear;
use App\Models\Grade;
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
 * Batch 8: TeacherJournal previously loaded any class by ID with zero
 * ownership check ($this->classId was unguarded Livewire state).
 */
class TeacherJournalTest extends TestCase
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

    protected function linkTeacher(User $user, int $classId, int $academicYearId): Teacher
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
            'class_id' => $classId,
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYearId,
        ]);

        return $teacher;
    }

    public function test_an_assigned_teacher_can_load_the_journal_for_their_class(): void
    {
        $year = $this->makeYear();
        [$class, $student] = $this->makeClassWithStudent($year);
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $year->id);

        $component = Livewire::actingAs($user)
            ->test(TeacherJournal::class)
            ->set('classId', $class->id)
            ->call('loadStudents');

        $this->assertCount(1, $component->get('students'));
        $this->assertSame($student->id, $component->get('students')->first()->id);
    }

    public function test_a_user_with_no_linked_teacher_record_cannot_load_the_journal(): void
    {
        $year = $this->makeYear();
        [$class] = $this->makeClassWithStudent($year);
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(TeacherJournal::class)
            ->set('classId', $class->id)
            ->call('loadStudents');

        $this->assertSame([], $component->get('students'));
    }

    public function test_a_teacher_not_assigned_to_the_class_cannot_load_its_journal(): void
    {
        $year = $this->makeYear();
        [$assignedClass] = $this->makeClassWithStudent($year, 'Assigned Class Student');
        [$otherClass] = $this->makeClassWithStudent($year, 'Other Class Student');
        $user = User::factory()->create();
        $this->linkTeacher($user, $assignedClass->id, $year->id);

        $component = Livewire::actingAs($user)
            ->test(TeacherJournal::class)
            ->set('classId', $otherClass->id)
            ->call('loadStudents');

        $this->assertSame([], $component->get('students'));
    }

    public function test_an_assignment_from_a_different_academic_year_does_not_grant_journal_access(): void
    {
        $activeYear = $this->makeYear(true);
        $pastYear = $this->makeYear(false);
        [$class] = $this->makeClassWithStudent($activeYear);
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $pastYear->id);

        $component = Livewire::actingAs($user)
            ->test(TeacherJournal::class)
            ->set('classId', $class->id)
            ->call('loadStudents');

        $this->assertSame([], $component->get('students'));
    }
}

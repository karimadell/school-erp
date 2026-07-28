<?php

namespace Tests\Feature\Teacher;

use App\Filament\Teacher\Pages\TeacherClasses;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Support\AcademicYearLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batch 8: TeacherClasses previously listed every class for every
 * subject the teacher merely qualified to teach (teacher_subject),
 * regardless of whether they're actually assigned this year. Now backed
 * by TeacherAssignment.
 */
class TeacherClassesTest extends TestCase
{
    use RefreshDatabase;

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);

        return SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A-' . uniqid(),
            'name_ar' => 'فصل',
            'name_ru' => 'Класс',
        ]);
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

    public function test_it_lists_only_classes_the_teacher_is_actually_assigned_to_this_year(): void
    {
        $year = $this->makeYear();
        $assignedClass = $this->makeClass();
        $unassignedClass = $this->makeClass();
        $subject = $this->makeSubject();

        $user = User::factory()->create();
        $teacher = Teacher::create([
            'user_id' => $user->id, 'first_name' => 'Anna', 'last_name' => 'Ivanova', 'is_active' => true,
        ]);

        TeacherAssignment::create([
            'teacher_id' => $teacher->id,
            'class_id' => $assignedClass->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
        ]);

        // Qualified (teacher_subject) but never actually assigned — must
        // not appear.
        $teacher->subjects()->attach($subject->id);

        $component = Livewire::actingAs($user)->test(TeacherClasses::class);

        $subjectGroups = $component->get('classes');
        $this->assertCount(1, $subjectGroups);

        $listedClassIds = $subjectGroups->first()->classes->pluck('id');
        $this->assertTrue($listedClassIds->contains($assignedClass->id));
        $this->assertFalse($listedClassIds->contains($unassignedClass->id));
    }

    public function test_a_teacher_with_only_a_qualification_and_no_real_assignment_sees_no_classes(): void
    {
        $subject = $this->makeSubject();
        $class = $this->makeClass();

        $user = User::factory()->create();
        $teacher = Teacher::create([
            'user_id' => $user->id, 'first_name' => 'Anna', 'last_name' => 'Ivanova', 'is_active' => true,
        ]);

        // Qualified via teacher_subject only — no TeacherAssignment at all.
        $teacher->subjects()->attach($subject->id);
        $class->grade()->first();

        $component = Livewire::actingAs($user)->test(TeacherClasses::class);

        $this->assertCount(0, $component->get('classes'));
    }

    public function test_a_user_with_no_linked_teacher_record_sees_no_classes(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(TeacherClasses::class);

        $this->assertSame([], $component->get('classes'));
    }

    public function test_an_assignment_from_a_different_academic_year_is_not_listed(): void
    {
        $activeYear = $this->makeYear(true);
        $pastYear = $this->makeYear(false);
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        $user = User::factory()->create();
        $teacher = Teacher::create([
            'user_id' => $user->id, 'first_name' => 'Anna', 'last_name' => 'Ivanova', 'is_active' => true,
        ]);

        AcademicYearLock::withoutLock(fn () => TeacherAssignment::create([
            'teacher_id' => $teacher->id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $pastYear->id,
        ]));

        $component = Livewire::actingAs($user)->test(TeacherClasses::class);

        $this->assertCount(0, $component->get('classes'));
    }
}

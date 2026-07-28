<?php

namespace Tests\Feature\Teacher;

use App\Filament\Teacher\Pages\TeacherJournal;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\LessonJournalEntry;
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
 * Batch 9: TeacherJournal rewritten from a read-only student roster into
 * a real lesson journal (date/title/notes/homework), scoped through
 * TeacherAssignment. Load-time, save-time, and ownership-on-edit
 * enforcement are all covered here.
 */
class TeacherJournalTest extends TestCase
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

    public function test_an_assigned_teacher_can_create_a_journal_entry(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        $class = $this->makeClass();
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $subject->id, $year->id);

        Livewire::actingAs($user)
            ->test(TeacherJournal::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->set('date', '2026-09-10')
            ->set('lessonTitle', 'Introduction')
            ->set('notes', 'Went well')
            ->set('homework', 'Read chapter 1')
            ->call('saveEntry')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('lesson_journal_entries', [
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'title' => 'Introduction',
            'homework' => 'Read chapter 1',
        ]);
    }

    public function test_an_assigned_teacher_can_edit_their_own_entry(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        $class = $this->makeClass();
        $user = User::factory()->create();
        $teacher = $this->linkTeacher($user, $class->id, $subject->id, $year->id);

        $entry = LessonJournalEntry::create([
            'teacher_id' => $teacher->id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'date' => '2026-09-10',
            'title' => 'Original title',
        ]);

        Livewire::actingAs($user)
            ->test(TeacherJournal::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->call('editEntry', $entry->id)
            ->set('lessonTitle', 'Updated title')
            ->call('saveEntry')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('lesson_journal_entries', [
            'id' => $entry->id,
            'title' => 'Updated title',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Load-time enforcement
    |--------------------------------------------------------------------------
    */

    public function test_a_user_with_no_linked_teacher_record_cannot_load_entries(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        $class = $this->makeClass();
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(TeacherJournal::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->call('loadEntries');

        $this->assertSame([], $component->get('entries'));
    }

    public function test_a_teacher_not_assigned_to_the_class_subject_cannot_load_entries(): void
    {
        $year = $this->makeYear();
        $assignedSubject = $this->makeSubject();
        $otherSubject = $this->makeSubject();
        $class = $this->makeClass();
        $user = User::factory()->create();
        $this->linkTeacher($user, $class->id, $assignedSubject->id, $year->id);

        $component = Livewire::actingAs($user)
            ->test(TeacherJournal::class)
            ->set('classId', $class->id)
            ->set('subjectId', $otherSubject->id)
            ->call('loadEntries');

        $this->assertSame([], $component->get('entries'));
    }

    public function test_an_assignment_from_a_different_academic_year_does_not_grant_journal_access(): void
    {
        $activeYear = $this->makeYear(true);
        $pastYear = $this->makeYear(false);
        $subject = $this->makeSubject();
        $class = $this->makeClass();
        $user = User::factory()->create();
        AcademicYearLock::withoutLock(fn () => $this->linkTeacher($user, $class->id, $subject->id, $pastYear->id));

        $component = Livewire::actingAs($user)
            ->test(TeacherJournal::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->call('loadEntries');

        $this->assertSame([], $component->get('entries'));
    }

    /*
    |--------------------------------------------------------------------------
    | Save-time enforcement and ownership
    |--------------------------------------------------------------------------
    */

    public function test_an_unassigned_teacher_cannot_create_an_entry(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        $class = $this->makeClass();
        $user = User::factory()->create();
        Teacher::create(['user_id' => $user->id, 'first_name' => 'A', 'last_name' => 'B', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(TeacherJournal::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->set('date', '2026-09-10')
            ->set('lessonTitle', 'Should not save')
            ->call('saveEntry');

        $this->assertDatabaseMissing('lesson_journal_entries', ['title' => 'Should not save']);
    }

    public function test_tampering_class_id_after_a_legitimate_load_blocks_the_save(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        $assignedClass = $this->makeClass();
        $foreignClass = $this->makeClass();
        $user = User::factory()->create();
        $this->linkTeacher($user, $assignedClass->id, $subject->id, $year->id);

        Livewire::actingAs($user)
            ->test(TeacherJournal::class)
            ->set('classId', $assignedClass->id)
            ->set('subjectId', $subject->id)
            ->call('loadEntries')
            ->set('classId', $foreignClass->id)
            ->set('date', '2026-09-10')
            ->set('lessonTitle', 'Tampered')
            ->call('saveEntry');

        $this->assertDatabaseMissing('lesson_journal_entries', ['title' => 'Tampered']);
    }

    /**
     * A teacher must not be able to edit another teacher's entry by
     * guessing/tampering with the entry ID — ownership is re-verified at
     * save time, not just when the edit form was originally populated.
     */
    public function test_a_teacher_cannot_edit_another_teachers_entry_via_a_tampered_id(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        $class = $this->makeClass();

        $ownerUser = User::factory()->create();
        $ownerTeacher = $this->linkTeacher($ownerUser, $class->id, $subject->id, $year->id);

        $entry = LessonJournalEntry::create([
            'teacher_id' => $ownerTeacher->id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'date' => '2026-09-10',
            'title' => 'Owner entry',
        ]);

        $attackerUser = User::factory()->create();
        $this->linkTeacher($attackerUser, $class->id, $subject->id, $year->id);

        Livewire::actingAs($attackerUser)
            ->test(TeacherJournal::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->set('editingId', $entry->id)
            ->set('date', '2026-09-10')
            ->set('lessonTitle', 'Hijacked title')
            ->call('saveEntry');

        $this->assertDatabaseHas('lesson_journal_entries', [
            'id' => $entry->id,
            'title' => 'Owner entry',
        ]);
        $this->assertDatabaseMissing('lesson_journal_entries', ['title' => 'Hijacked title']);
    }

    public function test_editing_an_entry_verifies_ownership_before_populating_the_form(): void
    {
        $year = $this->makeYear();
        $subject = $this->makeSubject();
        $class = $this->makeClass();

        $ownerUser = User::factory()->create();
        $ownerTeacher = $this->linkTeacher($ownerUser, $class->id, $subject->id, $year->id);

        $entry = LessonJournalEntry::create([
            'teacher_id' => $ownerTeacher->id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'date' => '2026-09-10',
            'title' => 'Owner entry',
        ]);

        $attackerUser = User::factory()->create();
        $this->linkTeacher($attackerUser, $class->id, $subject->id, $year->id);

        $component = Livewire::actingAs($attackerUser)
            ->test(TeacherJournal::class)
            ->set('classId', $class->id)
            ->set('subjectId', $subject->id)
            ->call('editEntry', $entry->id);

        $this->assertNull($component->get('editingId'));
    }
}

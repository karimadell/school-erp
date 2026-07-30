<?php

namespace App\Policies;

use App\Models\LessonJournalEntry;
use App\Models\User;

/**
 * Batch 9: governs the admin-side LessonJournalEntryResource only. The
 * Teacher Portal's own TeacherJournal page does not go through this
 * Policy — it authorizes via TeacherAssignment ownership directly,
 * matching the pattern established for TeacherAttendance/TeacherGrades
 * in Batch 8.
 */
class LessonJournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage journal entries');
    }

    public function view(User $user, LessonJournalEntry $lessonJournalEntry): bool
    {
        return $user->can('manage journal entries');
    }

    public function create(User $user): bool
    {
        return $user->can('manage journal entries');
    }

    public function update(User $user, LessonJournalEntry $lessonJournalEntry): bool
    {
        return $user->can('manage journal entries');
    }

    public function delete(User $user, LessonJournalEntry $lessonJournalEntry): bool
    {
        return $user->can('manage journal entries');
    }
}

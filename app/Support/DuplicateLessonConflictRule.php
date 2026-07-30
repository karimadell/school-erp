<?php

namespace App\Support;

use App\Contracts\TimetableConflictRule;
use App\Models\Timetable;

/**
 * The exact same class + day + period + subject + teacher already
 * exists. Deliberately checked before ClassConflictRule/TeacherConflictRule
 * (see TimetableConflictChecker's rule order) — an exact duplicate would
 * also match those broader rules, and the more specific message here
 * would never surface if a broader rule ran first.
 */
class DuplicateLessonConflictRule implements TimetableConflictRule
{
    public function check(TimetableSlot $slot): ?string
    {
        $exists = Timetable::where('class_id', $slot->classId)
            ->where('day_id', $slot->dayId)
            ->where('period_id', $slot->periodId)
            ->where('subject_id', $slot->subjectId)
            ->where('teacher_id', $slot->teacherId)
            ->when($slot->ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $slot->ignoreIds))
            ->exists();

        return $exists ? 'timetable.duplicate_lesson_conflict' : null;
    }
}

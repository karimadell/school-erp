<?php

namespace App\Support;

use App\Contracts\TimetableConflictRule;
use App\Models\Teacher;

/**
 * Batch 2 / TeacherAssignment Enforcement (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
 * the slot's teacher must actually be assigned (TeacherAssignment: teacher
 * x class x subject x academic year) to teach this subject to this class
 * in the active year. Reuses Teacher::isAssignedToClassSubject() — the
 * same authorization primitive already used throughout the Teacher
 * Portal (TeacherGrades, TeacherJournal) — rather than a new resolver.
 * teacher_subject (qualification) is never consulted here.
 */
class TeacherAssignmentRule implements TimetableConflictRule
{
    public function check(TimetableSlot $slot): ?string
    {
        $teacher = Teacher::find($slot->teacherId);

        if ($teacher === null || ! $teacher->isAssignedToClassSubject($slot->classId, $slot->subjectId)) {
            return 'timetable.teacher_not_assigned';
        }

        return null;
    }
}

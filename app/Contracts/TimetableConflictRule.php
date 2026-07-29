<?php

namespace App\Contracts;

use App\Support\TimetableSlot;

/**
 * Implemented by every individual timetable conflict check. Each rule is
 * independent and stateless — App\Services\TimetableConflictChecker is
 * the only thing that knows about the ordered list of rules; a new rule
 * (e.g. a future Curriculum/TeacherAssignment or WorkingDays check) is
 * added there, never inside a UI.
 */
interface TimetableConflictRule
{
    /**
     * @return string|null a translation key if this rule detects a
     *                      conflict for this slot, null otherwise.
     */
    public function check(TimetableSlot $slot): ?string;
}

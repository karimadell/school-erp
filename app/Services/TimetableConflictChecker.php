<?php

namespace App\Services;

use App\Contracts\TimetableConflictRule;
use App\Support\TimetableConflictResult;
use App\Support\TimetableSlot;

/**
 * The one conflict engine in the system — every timetable UI must call
 * this instead of running its own SQL. Orchestration only: it holds an
 * ordered list of independent, stateless rules (injected, not
 * hard-coded here) and stops at the first one that reports a conflict.
 * A future rule (e.g. Curriculum/TeacherAssignment or WorkingDays
 * checks) is added to the list this is constructed with — never inside
 * a controller, Livewire page, or Filament resource.
 */
class TimetableConflictChecker
{
    /** @param iterable<TimetableConflictRule> $rules */
    public function __construct(private readonly iterable $rules)
    {
    }

    public function check(TimetableSlot $slot): TimetableConflictResult
    {
        foreach ($this->rules as $rule) {
            $key = $rule->check($slot);

            if ($key !== null) {
                return new TimetableConflictResult([$key]);
            }
        }

        return new TimetableConflictResult([]);
    }
}

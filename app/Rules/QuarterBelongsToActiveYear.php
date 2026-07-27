<?php

namespace App\Rules;

use App\Models\AcademicYear;
use App\Models\Quarter;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * B9 (docs/IMPLEMENTATION_READINESS_ROADMAP.md): a submitted quarter_id must
 * belong to the currently active academic year. Policy, explicitly decided:
 * a quarter with a null academic_year_id fails (never treated as universal),
 * and if no academic year is currently active at all, validation fails with
 * a distinct message rather than silently skipping the check.
 */
class QuarterBelongsToActiveYear implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $activeYear = AcademicYear::where('is_active', true)->first();

        if (! $activeYear) {
            $fail(__('student_grades.no_active_academic_year'));

            return;
        }

        $quarter = Quarter::find($value);

        if (! $quarter) {
            $fail(__('student_grades.quarter_not_in_active_year'));

            return;
        }

        if ($quarter->academic_year_id === null || (int) $quarter->academic_year_id !== (int) $activeYear->id) {
            $fail(__('student_grades.quarter_not_in_active_year'));
        }
    }
}

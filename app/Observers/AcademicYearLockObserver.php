<?php

namespace App\Observers;

use App\Contracts\ResolvesAcademicYear;
use App\Support\AcademicYearLock;
use Illuminate\Validation\ValidationException;

/**
 * Item 2 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, B9): the single,
 * central enforcement point for historical-academic-year write locking.
 * Registered on every model implementing ResolvesAcademicYear (see
 * AppServiceProvider) so a new Filament resource or controller can't
 * silently skip it the way the old per-controller QuarterBelongsToActiveYear
 * rule was skipped by StudentGradeResource.
 *
 * Two distinct, deliberately different failures — approved policy:
 *  - resolveAcademicYear() returns null: the record has no determinable
 *    year at all (e.g. an Exam/StudentGrade with no quarter reference).
 *    This fails closed; it is never treated as "unscoped/exempt".
 *  - resolveAcademicYear() returns a year that is neither active nor
 *    currently unlocked: the record belongs to a closed historical year.
 */
class AcademicYearLockObserver
{
    public function creating(ResolvesAcademicYear $model): void
    {
        $this->guard($model);
    }

    public function updating(ResolvesAcademicYear $model): void
    {
        $this->guard($model);
    }

    public function deleting(ResolvesAcademicYear $model): void
    {
        $this->guard($model);
    }

    private function guard(ResolvesAcademicYear $model): void
    {
        if (AcademicYearLock::bypassed()) {
            return;
        }

        $year = $model->resolveAcademicYear();

        if ($year === null) {
            throw ValidationException::withMessages([
                'academic_year_lock' => [__('academic_years.unresolvable')],
            ]);
        }

        if ($year->is_active || $year->isUnlocked()) {
            return;
        }

        throw ValidationException::withMessages([
            'academic_year_lock' => [__('academic_years.locked')],
        ]);
    }
}

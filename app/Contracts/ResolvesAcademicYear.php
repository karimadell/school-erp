<?php

namespace App\Contracts;

use App\Models\AcademicYear;

/**
 * Implemented by every model whose writes must be blocked once the record
 * belongs to a non-active (historical) AcademicYear, unless that year has
 * a currently-active AcademicYearUnlock (see AcademicYearLockObserver).
 *
 * Returning null means "no academic year can be determined for this
 * record" — treated as a fail-closed rejection, not as "unscoped/exempt".
 */
interface ResolvesAcademicYear
{
    public function resolveAcademicYear(): ?AcademicYear;
}

<?php

namespace App\Support;

use Closure;

/**
 * The one, narrow, explicit escape hatch from AcademicYearLockObserver.
 * Deliberately NOT a blanket "console/testing environment bypasses
 * everything" rule — every call site that needs it (e.g. QuarterSeeder
 * seeding a historical year's quarters, or a test building a historical-
 * year fixture) must wrap only its own specific write in withoutLock(),
 * so each bypass stays visible and auditable in a diff rather than being
 * an invisible, blanket condition.
 */
class AcademicYearLock
{
    private static bool $bypassed = false;

    public static function withoutLock(Closure $callback): mixed
    {
        $previous = self::$bypassed;
        self::$bypassed = true;

        try {
            return $callback();
        } finally {
            self::$bypassed = $previous;
        }
    }

    public static function bypassed(): bool
    {
        return self::$bypassed;
    }
}

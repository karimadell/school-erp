<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * D1 Phase 2: single-row settings table for the timetable engine's
 * weekly non-working days, same shape as FinancePolicySetting. Default
 * (Friday + Saturday) is seeded by this table's own migration, not
 * hard-coded in App\Support\WorkingDays or TimetableGrid.
 */
class TimetableSetting extends Model
{
    protected $fillable = [
        'non_working_days',
    ];

    protected $casts = [
        'non_working_days' => 'array',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['non_working_days' => ['fri', 'sat']]);
    }
}

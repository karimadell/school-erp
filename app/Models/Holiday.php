<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * D1 Phase 2: holiday infrastructure only — not yet consulted anywhere.
 * See App\Contracts\HolidayCalendar for the seam a future date-based
 * timetable-generation feature must call.
 *
 * IMPORTANT: TimetableGrid::generateTimetable() produces a perpetual
 * weekly template keyed only on day-of-week + period, with no calendar
 * date of its own. A recurring "Wednesday period 3" slot has no specific
 * date to compare against a holiday's [start_date, end_date] range, so
 * holiday exclusion cannot take effect until timetable generation itself
 * becomes date-based — a larger, separate change (a future Academic
 * Calendar module), not part of this batch.
 */
class Holiday extends Model
{
    public const TYPE_PUBLIC = 'public_holiday';
    public const TYPE_SCHOOL_SPECIFIC = 'school_specific';
    public const TYPE_MID_YEAR_VACATION = 'mid_year_vacation';
    public const TYPE_WINTER_VACATION = 'winter_vacation';
    public const TYPE_SPRING_VACATION = 'spring_vacation';
    public const TYPE_SUMMER_VACATION = 'summer_vacation';
    public const TYPE_EMERGENCY_CLOSURE = 'emergency_closure';

    protected $fillable = [
        'start_date',
        'end_date',
        'type',
        'name',
        'academic_year_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }
}

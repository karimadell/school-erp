<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class AcademicCalendar extends Model implements ResolvesAcademicYear
{
    public const WEEKDAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    protected $fillable = [
        'academic_year_id',
        'weekly_days_off',
        'default_bell_schedule_id',
    ];

    protected $casts = [
        'weekly_days_off' => 'array',
        'default_bell_schedule_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $calendar): void {
            if (! $calendar->academicYear?->is_active) {
                throw ValidationException::withMessages([
                    'academic_year_id' => __('academic_calendar.validation.active_academic_year_required'),
                ]);
            }
        });

        static::saving(function (self $calendar): void {
            $days = array_values(array_unique($calendar->weekly_days_off ?? []));

            if ($days === [] || array_diff($days, self::WEEKDAYS) !== []) {
                throw ValidationException::withMessages([
                    'weekly_days_off' => __('academic_calendar.validation.weekly_days_off'),
                ]);
            }

            $calendar->weekly_days_off = $days;

            if ($calendar->defaultBellSchedule
                && $calendar->defaultBellSchedule->academic_year_id !== $calendar->academic_year_id) {
                throw ValidationException::withMessages([
                    'default_bell_schedule_id' => __('academic_calendar.validation.bell_schedule_year_mismatch'),
                ]);
            }
        });
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function events()
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function defaultBellSchedule()
    {
        return $this->belongsTo(BellSchedule::class, 'default_bell_schedule_id');
    }

    public function resolveAcademicYear(): ?AcademicYear
    {
        return $this->academicYear;
    }
}

<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CalendarEvent extends Model implements ResolvesAcademicYear
{
    public const TYPE_OFFICIAL_HOLIDAY = 'official_holiday';

    public const TYPE_SCHOOL_HOLIDAY = 'school_holiday';

    public const TYPE_SCHOOL_EVENT = 'school_event';

    public const TYPE_TEACHING_OVERRIDE = 'teaching_override';

    public const TYPE_BELL_SCHEDULE_OVERRIDE = 'bell_schedule_override';

    public const EFFECT_NON_TEACHING = 'non_teaching';

    public const EFFECT_TEACHING_DAY = 'teaching_day';

    public const EFFECT_SHORTENED = 'shortened';

    public const TYPES = [
        self::TYPE_OFFICIAL_HOLIDAY,
        self::TYPE_SCHOOL_HOLIDAY,
        self::TYPE_SCHOOL_EVENT,
        self::TYPE_TEACHING_OVERRIDE,
        self::TYPE_BELL_SCHEDULE_OVERRIDE,
    ];

    public const EFFECTS = [
        self::EFFECT_NON_TEACHING,
        self::EFFECT_TEACHING_DAY,
        self::EFFECT_SHORTENED,
    ];

    protected $fillable = [
        'academic_calendar_id',
        'name',
        'start_date',
        'end_date',
        'type',
        'effect',
        'bell_schedule_id',
        'shift',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'bell_schedule_id' => 'integer',
        'shift' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            $errors = [];

            if (! in_array($event->type, self::TYPES, true)) {
                $errors['type'] = __('academic_calendar.validation.invalid_event_type');
            }

            if ($event->start_date && $event->end_date && $event->end_date->lt($event->start_date)) {
                $errors['end_date'] = __('academic_calendar.validation.end_before_start');
            }

            if ($event->calendar && $event->start_date && $event->end_date) {
                $year = $event->calendar->academicYear;
                if ($year && ($event->start_date->lt($year->start_date) || $event->end_date->gt($year->end_date))) {
                    $errors['start_date'] = __('academic_calendar.validation.event_outside_year');
                }
            }

            if ($event->type === self::TYPE_TEACHING_OVERRIDE && ! in_array($event->effect, self::EFFECTS, true)) {
                $errors['effect'] = __('academic_calendar.validation.teaching_override_effect');
            }

            if (in_array($event->type, [self::TYPE_OFFICIAL_HOLIDAY, self::TYPE_SCHOOL_HOLIDAY], true)) {
                $event->effect = self::EFFECT_NON_TEACHING;
            }

            if ($event->type === self::TYPE_BELL_SCHEDULE_OVERRIDE) {
                if (! $event->bell_schedule_id) {
                    $errors['bell_schedule_id'] = __('academic_calendar.validation.bell_schedule_required');
                }
                if ($event->start_date && $event->end_date && ! $event->start_date->equalTo($event->end_date)) {
                    $errors['end_date'] = __('academic_calendar.validation.bell_override_single_day');
                }
            }

            if ($event->bellSchedule && $event->calendar) {
                if ($event->bellSchedule->academic_year_id !== $event->calendar->academic_year_id) {
                    $errors['bell_schedule_id'] = __('academic_calendar.validation.bell_schedule_year_mismatch');
                }

                if ($event->shift !== null && $event->bellSchedule->shift !== $event->shift) {
                    $errors['bell_schedule_id'] = __('academic_calendar.validation.bell_schedule_shift_mismatch');
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        });
    }

    public function calendar()
    {
        return $this->belongsTo(AcademicCalendar::class, 'academic_calendar_id');
    }

    public function resolveAcademicYear(): ?AcademicYear
    {
        return $this->calendar?->academicYear;
    }

    public function bellSchedule()
    {
        return $this->belongsTo(BellSchedule::class);
    }
}

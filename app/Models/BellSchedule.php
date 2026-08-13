<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BellSchedule extends Model implements ResolvesAcademicYear
{
    protected $fillable = [
        'academic_year_id',
        'name',
        'shift',
        'is_default',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'shift' => 'integer',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'default_slot' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $schedule): void {
            if (($schedule->shift ?? 0) < 1) {
                throw ValidationException::withMessages([
                    'shift' => __('bell_schedule.validation.shift_min'),
                ]);
            }

            if (! $schedule->is_active) {
                $schedule->is_default = false;
            }

            $schedule->default_slot = $schedule->is_active && $schedule->is_default ? 1 : null;

            if (! $schedule->is_active || ! $schedule->is_default) {
                return;
            }

            static::query()
                ->where('academic_year_id', $schedule->academic_year_id)
                ->where('shift', $schedule->shift)
                ->whereKeyNot($schedule->getKey())
                ->where('is_default', true)
                ->lockForUpdate()
                ->get()
                ->each
                ->update(['is_default' => false, 'default_slot' => null]);
        });
    }

    public function save(array $options = [])
    {
        return DB::transaction(fn () => parent::save($options));
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function periods()
    {
        return $this->hasMany(BellSchedulePeriod::class)->orderBy('period_number');
    }

    public function defaultForCalendars()
    {
        return $this->hasMany(AcademicCalendar::class, 'default_bell_schedule_id');
    }

    public function calendarEvents()
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function resolveAcademicYear(): ?AcademicYear
    {
        return $this->academicYear()->first();
    }
}

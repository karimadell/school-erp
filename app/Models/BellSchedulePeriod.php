<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class BellSchedulePeriod extends Model implements ResolvesAcademicYear
{
    protected $fillable = [
        'bell_schedule_id',
        'period_number',
        'label',
        'starts_at',
        'ends_at',
        'break_after_minutes',
        'is_active',
    ];

    protected $casts = [
        'period_number' => 'integer',
        'break_after_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $period): void {
            $start = Carbon::createFromFormat('H:i', substr((string) $period->starts_at, 0, 5));
            $end = Carbon::createFromFormat('H:i', substr((string) $period->ends_at, 0, 5));

            if (! $start->lt($end)) {
                throw ValidationException::withMessages([
                    'ends_at' => __('bell_schedule.validation.end_after_start'),
                ]);
            }

            $duplicate = static::query()
                ->where('bell_schedule_id', $period->bell_schedule_id)
                ->where('period_number', $period->period_number)
                ->when($period->exists, fn ($query) => $query->whereKeyNot($period->getKey()))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'period_number' => __('bell_schedule.validation.duplicate_period_number'),
                ]);
            }

            $overlaps = static::query()
                ->where('bell_schedule_id', $period->bell_schedule_id)
                ->when($period->exists, fn ($query) => $query->whereKeyNot($period->getKey()))
                ->where('starts_at', '<', $period->ends_at)
                ->where('ends_at', '>', $period->starts_at)
                ->exists();

            if ($overlaps) {
                throw ValidationException::withMessages([
                    'starts_at' => __('bell_schedule.validation.period_overlap'),
                ]);
            }
        });
    }

    public function bellSchedule()
    {
        return $this->belongsTo(BellSchedule::class);
    }

    public function resolveAcademicYear(): ?AcademicYear
    {
        return $this->bellSchedule?->academicYear()->first();
    }
}

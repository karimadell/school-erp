<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class TimetableVersion extends Model implements ResolvesAcademicYear
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    private static bool $publicationMutationAllowed = false;

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected $fillable = [
        'academic_year_id', 'name', 'status', 'effective_from', 'effective_to', 'notes',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'published_at' => 'datetime',
        'published_slot' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $version): void {
            if ($version->exists && $version->getOriginal('status') === self::STATUS_PUBLISHED && ! self::$publicationMutationAllowed) {
                throw ValidationException::withMessages([
                    'status' => __('timetable_version.validation.published_immutable'),
                ]);
            }

            if (! in_array($version->status, self::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => __('timetable_version.validation.invalid_status')]);
            }

            $from = Carbon::parse($version->effective_from);
            $to = Carbon::parse($version->effective_to);

            if ($from->gt($to)) {
                throw ValidationException::withMessages(['effective_to' => __('timetable_version.validation.invalid_dates')]);
            }

            $year = $version->academicYear()->first();
            if ($year && ($from->lt($year->start_date) || $to->gt($year->end_date))) {
                throw ValidationException::withMessages(['effective_from' => __('timetable_version.validation.outside_academic_year')]);
            }

            if (! self::$publicationMutationAllowed && $version->status !== self::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => __('timetable_version.validation.publish_through_service')]);
            }

            $version->published_slot = $version->status === self::STATUS_PUBLISHED ? 1 : null;
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages([
                'timetable_version' => __('timetable_version.validation.hard_delete_forbidden'),
            ]);
        });
    }

    public static function allowingPublicationMutation(Closure $callback): mixed
    {
        $previous = self::$publicationMutationAllowed;
        self::$publicationMutationAllowed = true;

        try {
            return $callback();
        } finally {
            self::$publicationMutationAllowed = $previous;
        }
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function timetables()
    {
        return $this->hasMany(\App\Models\Academic\Timetable::class);
    }

    public function entries()
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function resolveAcademicYear(): ?AcademicYear
    {
        return $this->academicYear()->first();
    }
}

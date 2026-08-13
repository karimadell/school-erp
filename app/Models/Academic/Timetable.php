<?php

namespace App\Models\Academic;

use App\Contracts\ResolvesAcademicYear;
use App\Models\AcademicYear;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Timetable extends Model implements ResolvesAcademicYear
{
    protected $table = 'academic_timetables';

    protected $fillable = ['timetable_version_id', 'name', 'notes'];

    protected static function booted(): void
    {
        $guardPublished = function (self $timetable): void {
            $versionIds = array_filter([
                $timetable->timetable_version_id,
                $timetable->exists ? $timetable->getOriginal('timetable_version_id') : null,
            ]);

            if (TimetableVersion::query()->whereKey($versionIds)
                ->where('status', '!=', TimetableVersion::STATUS_DRAFT)->exists()) {
                throw ValidationException::withMessages([
                    'version' => __('timetable_version.validation.published_immutable'),
                ]);
            }
        };

        static::saving($guardPublished);
        static::deleting($guardPublished);
    }

    public function version()
    {
        return $this->belongsTo(TimetableVersion::class, 'timetable_version_id');
    }

    public function entries()
    {
        return $this->hasMany(TimetableEntry::class, 'academic_timetable_id');
    }

    public function resolveAcademicYear(): ?AcademicYear
    {
        return $this->version?->academicYear()->first();
    }
}

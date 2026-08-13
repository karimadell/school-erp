<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use App\Models\Academic\Timetable as AcademicTimetable;
use App\Services\TimetableEntryValidator;
use App\Services\TimetableEntryWriteLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimetableEntry extends Model implements ResolvesAcademicYear
{
    protected $fillable = [
        'timetable_version_id', 'academic_timetable_id', 'weekday', 'bell_schedule_id',
        'bell_schedule_period_id', 'classroom_id', 'teacher_assignment_id',
        'curriculum_id', 'subject_id', 'class_id',
    ];

    protected $casts = ['weekday' => 'integer'];

    protected static function booted(): void
    {
        $guardPublished = function (self $entry): void {
            $versionIds = array_filter([
                $entry->timetable_version_id,
                $entry->exists ? $entry->getOriginal('timetable_version_id') : null,
            ]);

            if (TimetableVersion::query()->whereKey($versionIds)
                ->where('status', '!=', TimetableVersion::STATUS_DRAFT)->exists()) {
                throw ValidationException::withMessages(['version' => __('timetable_version.validation.published_immutable')]);
            }
        };

        static::saving(function (self $entry) use ($guardPublished): void {
            if (($entry->weekday ?? 0) < 1 || $entry->weekday > 7) {
                throw ValidationException::withMessages(['weekday' => __('timetable_version.validation.invalid_weekday')]);
            }

            $guardPublished($entry);
            app(TimetableEntryValidator::class)->validate($entry, requireComplete: false);
        });

        static::deleting($guardPublished);
    }

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            app(TimetableEntryWriteLock::class)->lock([
                $this->exists ? $this->getOriginal('timetable_version_id') : null,
                $this->timetable_version_id,
            ]);

            return parent::save($options);
        });
    }

    public function delete()
    {
        return DB::transaction(function () {
            app(TimetableEntryWriteLock::class)->lock($this->timetable_version_id);

            return parent::delete();
        });
    }

    public function version()
    {
        return $this->belongsTo(TimetableVersion::class, 'timetable_version_id');
    }

    public function timetable()
    {
        return $this->belongsTo(AcademicTimetable::class, 'academic_timetable_id');
    }

    public function bellSchedule()
    {
        return $this->belongsTo(BellSchedule::class);
    }

    public function period()
    {
        return $this->belongsTo(BellSchedulePeriod::class, 'bell_schedule_period_id');
    }

    public function classroom()
    {
        return $this->belongsTo(PhysicalClassroom::class, 'classroom_id');
    }

    public function teacherAssignment()
    {
        return $this->belongsTo(TeacherAssignment::class);
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function resolveAcademicYear(): ?AcademicYear
    {
        return $this->version?->academicYear()->first();
    }
}

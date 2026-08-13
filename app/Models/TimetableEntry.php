<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use App\Models\Academic\Timetable as AcademicTimetable;
use Illuminate\Database\Eloquent\Model;
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
                ->where('status', TimetableVersion::STATUS_PUBLISHED)->exists()) {
                throw ValidationException::withMessages(['version' => __('timetable_version.validation.published_immutable')]);
            }
        };

        static::saving(function (self $entry) use ($guardPublished): void {
            if (($entry->weekday ?? 0) < 1 || $entry->weekday > 7) {
                throw ValidationException::withMessages(['weekday' => __('timetable_version.validation.invalid_weekday')]);
            }

            $guardPublished($entry);
            $entry->validateReferences();
        });

        static::deleting($guardPublished);
    }

    private function validateReferences(): void
    {
        $version = TimetableVersion::find($this->timetable_version_id);
        $timetable = AcademicTimetable::find($this->academic_timetable_id);

        if ($version && $timetable && $timetable->timetable_version_id !== $version->id) {
            throw ValidationException::withMessages([
                'academic_timetable_id' => __('timetable_version.validation.header_version_mismatch'),
            ]);
        }

        if (! $version) {
            return;
        }

        $yearId = $version->academic_year_id;
        $yearReferences = [
            'bell_schedule_id' => [BellSchedule::class, $this->bell_schedule_id],
            'classroom_id' => [PhysicalClassroom::class, $this->classroom_id],
            'teacher_assignment_id' => [TeacherAssignment::class, $this->teacher_assignment_id],
            'curriculum_id' => [Curriculum::class, $this->curriculum_id],
        ];

        foreach ($yearReferences as $field => [$model, $id]) {
            if ($id && $model::query()->whereKey($id)->where('academic_year_id', '!=', $yearId)->exists()) {
                throw ValidationException::withMessages([
                    $field => __('timetable_version.validation.cross_year_reference'),
                ]);
            }
        }

        if ($this->bell_schedule_period_id && BellSchedulePeriod::query()
            ->whereKey($this->bell_schedule_period_id)
            ->where('bell_schedule_id', '!=', $this->bell_schedule_id)
            ->exists()) {
            throw ValidationException::withMessages([
                'bell_schedule_period_id' => __('timetable_version.validation.period_schedule_mismatch'),
            ]);
        }
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

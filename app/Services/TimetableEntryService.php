<?php

namespace App\Services;

use App\Models\Curriculum;
use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use Illuminate\Support\Facades\DB;

class TimetableEntryService
{
    public function __construct(
        private readonly TimetableEntryValidator $validator,
        private readonly TimetableEntryWriteLock $writeLock,
    ) {}

    public function create(array $attributes): TimetableEntry
    {
        return DB::transaction(function () use ($attributes) {
            $entry = new TimetableEntry($this->withCurriculum($attributes));
            $this->writeLock->lock($entry->timetable_version_id);
            $this->validator->validate($entry);
            $entry->save();

            return $entry->refresh();
        });
    }

    public function update(TimetableEntry $entry, array $attributes): TimetableEntry
    {
        return DB::transaction(function () use ($entry, $attributes) {
            $this->writeLock->lock([
                $entry->getOriginal('timetable_version_id'),
                $attributes['timetable_version_id'] ?? $entry->timetable_version_id,
            ]);
            $merged = array_merge($entry->getAttributes(), $attributes);
            if ((array_key_exists('class_id', $attributes) || array_key_exists('subject_id', $attributes))
                && ! array_key_exists('curriculum_id', $attributes)) {
                unset($merged['curriculum_id']);
            }

            $entry->fill($this->withCurriculum($merged));
            $this->validator->validate($entry);
            $entry->save();

            return $entry->refresh();
        });
    }

    public function delete(TimetableEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $this->writeLock->lock($entry->timetable_version_id);
            $this->validator->validate($entry);
            $entry->delete();
        });
    }

    private function withCurriculum(array $attributes): array
    {
        if (! empty($attributes['curriculum_id']) || empty($attributes['timetable_version_id'])
            || empty($attributes['class_id']) || empty($attributes['subject_id'])) {
            return $attributes;
        }

        $yearId = TimetableVersion::find($attributes['timetable_version_id'])?->academic_year_id;
        $gradeId = SchoolClass::find($attributes['class_id'])?->grade_id;
        $attributes['curriculum_id'] = Curriculum::query()
            ->where('academic_year_id', $yearId)
            ->where('grade_id', $gradeId)
            ->where('subject_id', $attributes['subject_id'])
            ->where('is_active', true)
            ->value('id');

        return $attributes;
    }
}

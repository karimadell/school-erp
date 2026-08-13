<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\TimetableVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimetableVersionService
{
    public function publish(TimetableVersion $version, User $publisher): TimetableVersion
    {
        if ($version->status !== TimetableVersion::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => __('timetable_version.validation.only_draft_publishable')]);
        }

        return DB::transaction(function () use ($version, $publisher) {
            TimetableVersion::query()
                ->where('academic_year_id', $version->academic_year_id)
                ->lockForUpdate()
                ->get();

            TimetableVersion::allowingPublicationMutation(function () use ($version, $publisher): void {
                TimetableVersion::query()
                    ->where('academic_year_id', $version->academic_year_id)
                    ->where('status', TimetableVersion::STATUS_PUBLISHED)
                    ->whereKeyNot($version->getKey())
                    ->get()
                    ->each(function (TimetableVersion $published): void {
                        $published->forceFill([
                            'status' => TimetableVersion::STATUS_ARCHIVED,
                            'published_slot' => null,
                        ])->save();
                    });

                $version->forceFill([
                    'status' => TimetableVersion::STATUS_PUBLISHED,
                    'published_by' => $publisher->id,
                    'published_at' => now(),
                    'published_slot' => 1,
                ])->save();
            });

            return $version->refresh();
        });
    }

    public function activeVersionFor(AcademicYear|int $academicYear, ?CarbonInterface $date = null): ?TimetableVersion
    {
        $academicYearId = $academicYear instanceof AcademicYear ? $academicYear->id : $academicYear;
        $date ??= now();

        return TimetableVersion::query()
            ->where('academic_year_id', $academicYearId)
            ->where('status', TimetableVersion::STATUS_PUBLISHED)
            ->whereDate('effective_from', '<=', $date)
            ->whereDate('effective_to', '>=', $date)
            ->first();
    }
}

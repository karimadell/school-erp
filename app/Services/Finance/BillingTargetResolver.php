<?php

namespace App\Services\Finance;

use App\Models\BillingBatch;
use App\Models\Enrollment;
use Illuminate\Support\Collection;

/**
 * Resolves the concrete set of students a billing batch targets.
 *
 * The class roster is derived from Enrollment for the batch's selected
 * academic year — never from the stale students.class_id, which reflects only
 * the student's current-year placement and drifts across years. Explicit
 * per-student inclusions and exclusions are then merged in, and the final set
 * is deduplicated so a student appears at most once regardless of how many
 * classes/rules selected them.
 *
 * Note: withdrawn/graduated/inactive enrollments are still returned here (they
 * belong to the class for the year); eligibility classification decides whether
 * each resolved student is billable or skipped.
 */
class BillingTargetResolver
{
    /**
     * @return Collection<int, int> Distinct, ordered student ids.
     */
    public function resolve(BillingBatch $batch): Collection
    {
        $batch->loadMissing(['classTargets', 'studentTargets']);

        $base = $this->baseStudentIds($batch);
        $included = $batch->includedStudentIds();
        $excluded = $batch->excludedStudentIds();

        return $base
            ->merge($included)
            ->reject(fn ($id) => $excluded->contains($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * Students selected by the batch's base targeting mode, resolved through
     * the selected academic year's enrollments.
     *
     * @return Collection<int, int>
     */
    private function baseStudentIds(BillingBatch $batch): Collection
    {
        $query = Enrollment::query()->where('academic_year_id', $batch->academic_year_id);

        if ($batch->target_mode === BillingBatch::TARGET_MODE_CLASSES) {
            $classIds = $batch->classTargets->pluck('class_id');

            if ($classIds->isEmpty()) {
                return collect();
            }

            $query->whereIn('class_id', $classIds);
        }

        return $query->pluck('student_id');
    }
}

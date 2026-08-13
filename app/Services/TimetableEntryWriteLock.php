<?php

namespace App\Services;

use App\Models\TimetableVersion;

class TimetableEntryWriteLock
{
    /**
     * Serialize manual-entry writes at the stable version parent. Sorting
     * keeps version reassignment locks deterministic and avoids deadlocks.
     */
    public function lock(array|int|null $versionIds): void
    {
        $ids = collect((array) $versionIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        TimetableVersion::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}

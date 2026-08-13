<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\PhysicalClassroom;
use Illuminate\Database\Eloquent\Collection;

class ClassroomService
{
    public function create(array $attributes): PhysicalClassroom
    {
        return PhysicalClassroom::create($attributes);
    }

    public function update(PhysicalClassroom $classroom, array $attributes): PhysicalClassroom
    {
        $classroom->update($attributes);

        return $classroom->refresh();
    }

    public function deactivate(PhysicalClassroom $classroom): PhysicalClassroom
    {
        $classroom->update(['is_active' => false]);

        return $classroom->refresh();
    }

    /**
     * Canonical future timetable picker source. Inactive rooms are never
     * offered for a new assignment; historical records remain queryable.
     */
    public function availableFor(AcademicYear|int $academicYear): Collection
    {
        $academicYearId = $academicYear instanceof AcademicYear ? $academicYear->id : $academicYear;

        return PhysicalClassroom::query()
            ->where('academic_year_id', $academicYearId)
            ->where('is_active', true)
            ->orderBy('building')
            ->orderBy('floor')
            ->orderBy('code')
            ->get();
    }
}

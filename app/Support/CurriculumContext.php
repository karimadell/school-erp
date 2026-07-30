<?php

namespace App\Support;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\SchoolClass;
use Illuminate\Support\Collection;

/**
 * Batch 1 / Curriculum Enforcement (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
 * the single place that resolves "the active academic year + this
 * class's grade" and looks up Curriculum within that scope. Extracted
 * after this exact lookup was duplicated across TimetableGrid::
 * curriculumSubjectsForClass(), CurriculumSubjectRule, and
 * CurriculumWeeklyHoursRule.
 */
final class CurriculumContext
{
    private function __construct(
        public readonly int $academicYearId,
        public readonly int $gradeId,
    ) {
    }

    public static function forClass(int $classId): ?self
    {
        $gradeId = SchoolClass::find($classId)?->grade_id;
        $activeYearId = AcademicYear::where('is_active', true)->value('id');

        if ($gradeId === null || $activeYearId === null) {
            return null;
        }

        return new self($activeYearId, $gradeId);
    }

    public function curriculumFor(int $subjectId): ?Curriculum
    {
        return Curriculum::where('academic_year_id', $this->academicYearId)
            ->where('grade_id', $this->gradeId)
            ->where('subject_id', $subjectId)
            ->first();
    }

    public function subjectIds(): Collection
    {
        return Curriculum::where('academic_year_id', $this->academicYearId)
            ->where('grade_id', $this->gradeId)
            ->pluck('subject_id');
    }
}

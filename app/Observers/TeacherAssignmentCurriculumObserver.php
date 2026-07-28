<?php

namespace App\Observers;

use App\Models\Curriculum;
use App\Models\SchoolClass;
use App\Models\TeacherAssignment;
use Illuminate\Validation\ValidationException;

/**
 * Batch 11 / C6 (docs/IMPLEMENTATION_READINESS_ROADMAP.md): validates
 * that a newly created TeacherAssignment's subject is actually part of
 * the assigned class's grade's approved Curriculum for that academic
 * year.
 *
 * Validation layer only — validates new writes, rejects invalid data,
 * never creates or modifies Curriculum, never reconciles historical
 * records, introduces no new authorization logic. creating() only,
 * never updating(). Kept separate from CurriculumValidationObserver
 * (Exam / StudentGrade) per decision — a TeacherAssignment is an
 * authorization grant, not a graded data row.
 *
 * Registered in AppServiceProvider AFTER AcademicYearLockObserver — by
 * the time this runs, the academic year has already been successfully
 * resolved and is either active or explicitly unlocked; this observer
 * only ever adds a second, independent question ("is this subject
 * actually part of the curriculum") on top of that, never a substitute
 * for it.
 */
class TeacherAssignmentCurriculumObserver
{
    public function creating(TeacherAssignment $assignment): void
    {
        $gradeId = SchoolClass::find($assignment->class_id)?->grade_id;

        if ($gradeId === null) {
            throw ValidationException::withMessages([
                'curriculum' => [__('curriculum.grade_unresolvable')],
            ]);
        }

        $exists = Curriculum::where('academic_year_id', $assignment->academic_year_id)
            ->where('grade_id', $gradeId)
            ->where('subject_id', $assignment->subject_id)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'curriculum' => [__('curriculum.not_in_curriculum')],
            ]);
        }
    }
}

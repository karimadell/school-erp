<?php

namespace App\Observers;

use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\StudentSubjectEnrollment;
use Illuminate\Validation\ValidationException;

/**
 * Batch 11 / C2 (docs/IMPLEMENTATION_READINESS_ROADMAP.md): validates a
 * new student elective election. Rejects creation when:
 *  - the targeted Curriculum row is 'mandatory' (every student in the
 *    grade already takes it implicitly — nothing to individually elect);
 *  - the student's active Enrollment for the Curriculum's academic year
 *    doesn't have a grade_id matching Curriculum.grade_id.
 *
 * Validation layer only — validates new writes, rejects invalid data,
 * never creates or modifies Curriculum/Enrollment, never reconciles
 * historical records, introduces no new authorization logic. creating()
 * only, never updating().
 *
 * Registered in AppServiceProvider AFTER AcademicYearLockObserver — by
 * the time this runs, the academic year has already been successfully
 * resolved and is either active or explicitly unlocked; this observer
 * only ever adds a second, independent question on top of that, never a
 * substitute for it.
 */
class StudentSubjectEnrollmentValidationObserver
{
    public function creating(StudentSubjectEnrollment $enrollment): void
    {
        $curriculum = Curriculum::find($enrollment->curriculum_id);

        if ($curriculum === null) {
            throw ValidationException::withMessages([
                'student_subject_enrollment' => [__('student_subject_enrollments.curriculum_unresolvable')],
            ]);
        }

        if ($curriculum->type === Curriculum::TYPE_MANDATORY) {
            throw ValidationException::withMessages([
                'student_subject_enrollment' => [__('student_subject_enrollments.mandatory_not_electable')],
            ]);
        }

        $studentGradeId = Enrollment::where('student_id', $enrollment->student_id)
            ->where('academic_year_id', $curriculum->academic_year_id)
            ->where('is_active', true)
            ->value('grade_id');

        if ($studentGradeId === null || $studentGradeId !== $curriculum->grade_id) {
            throw ValidationException::withMessages([
                'student_subject_enrollment' => [__('student_subject_enrollments.grade_mismatch')],
            ]);
        }
    }
}

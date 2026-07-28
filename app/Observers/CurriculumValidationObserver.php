<?php

namespace App\Observers;

use App\Models\Curriculum;
use App\Models\Exam;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentGrade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Batch 11 / C3 (docs/IMPLEMENTATION_READINESS_ROADMAP.md): validates
 * that a newly created Exam/StudentGrade's subject is actually part of
 * the resolved grade's approved Curriculum for that academic year.
 *
 * Validation layer only — validates new writes, rejects invalid data,
 * never creates or modifies Curriculum, never reconciles historical
 * records, introduces no new authorization logic. creating() only,
 * never updating() — a pre-existing, already-non-compliant historical
 * row is never retroactively touched by this observer merely because
 * some unrelated field on it is later edited.
 *
 * Registered in AppServiceProvider AFTER AcademicYearLockObserver (and,
 * for Exam, after ExamSnapshotObserver too) — by the time this runs,
 * the academic year has already been successfully resolved and is
 * either active or explicitly unlocked; this observer only ever adds a
 * second, independent question ("is this subject actually part of the
 * curriculum") on top of that, never a substitute for it.
 */
class CurriculumValidationObserver
{
    public function creating(Model $model): void
    {
        if ($model instanceof Exam) {
            $this->guardExam($model);
        } elseif ($model instanceof StudentGrade) {
            $this->guardStudentGrade($model);
        }
    }

    private function guardExam(Exam $exam): void
    {
        $this->assertInCurriculum(
            $exam->resolveAcademicYear()?->id,
            $exam->grade_id,
            $exam->subject_id
        );
    }

    private function guardStudentGrade(StudentGrade $grade): void
    {
        $this->assertInCurriculum(
            $grade->resolveAcademicYear()?->id,
            $this->resolveGradeId($grade),
            $grade->subject_id
        );
    }

    /**
     * StudentGrade carries no grade_id snapshot of its own (Item 6 only
     * added that to Exam). Prefers the linked exam's snapshot; falls
     * back to the student's current class for quarter-only entries with
     * no exam_id.
     */
    private function resolveGradeId(StudentGrade $grade): ?int
    {
        if ($grade->exam_id !== null) {
            return Exam::find($grade->exam_id)?->grade_id;
        }

        $student = Student::find($grade->student_id);

        if ($student === null || $student->class_id === null) {
            return null;
        }

        return SchoolClass::find($student->class_id)?->grade_id;
    }

    private function assertInCurriculum(?int $academicYearId, ?int $gradeId, ?int $subjectId): void
    {
        if ($academicYearId === null || $gradeId === null) {
            throw ValidationException::withMessages([
                'curriculum' => [__('curriculum.grade_unresolvable')],
            ]);
        }

        $exists = Curriculum::where('academic_year_id', $academicYearId)
            ->where('grade_id', $gradeId)
            ->where('subject_id', $subjectId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'curriculum' => [__('curriculum.not_in_curriculum')],
            ]);
        }
    }
}

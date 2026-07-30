<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use Illuminate\Database\Eloquent\Model;

/**
 * Batch 11 / C2: records that a student has individually elected a
 * specific Curriculum row (elective / optional-enrichment subjects
 * only — mandatory subjects apply to the whole grade implicitly and
 * need no per-student record). References curriculum_id only — no
 * duplicated subject_id/academic_year_id/grade_id columns; that scope
 * travels with the Curriculum FK.
 */
class StudentSubjectEnrollment extends Model implements ResolvesAcademicYear
{
    protected $fillable = [
        'student_id',
        'curriculum_id',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class);
    }

    /**
     * A fresh lookup, not a cached relation — matches the same
     * staleness-avoidance pattern used by every other resolveAcademicYear()
     * implementation in the codebase.
     */
    public function resolveAcademicYear(): ?AcademicYear
    {
        $curriculum = Curriculum::find($this->curriculum_id);

        if ($curriculum === null) {
            return null;
        }

        return AcademicYear::find($curriculum->academic_year_id);
    }
}

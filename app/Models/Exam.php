<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use Illuminate\Database\Eloquent\Model;

class Exam extends Model implements ResolvesAcademicYear
{

    protected $fillable = [

        'name',
        'subject_id',
        'class_id',
        'quarter_id',
        'exam_date',
        'max_score',
        'academic_year_id',
        'grade_id',
        'stage_id',

    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function class()
    {
        return $this->belongsTo(SchoolClass::class,'class_id');
    }

    public function quarter()
    {
        return $this->belongsTo(Quarter::class);
    }

    public function grades()
    {
        return $this->hasMany(StudentGrade::class);
    }

    /**
     * Item 6: written once at creation by ExamSnapshotObserver, never
     * re-synced — the historical source of truth for which academic
     * year/grade/stage this exam belonged to, independent of whatever
     * SchoolClass.grade_id/Grade.stage_id/Quarter say today.
     */
    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function stage()
    {
        return $this->belongsTo(Stage::class);
    }

    /**
     * Item 2, approved policy: an Exam with no determinable academic year
     * fails closed — this is not treated as "unscoped/exempt".
     *
     * Item 6: the stored academic_year_id snapshot is now the historical
     * source of truth once present. Quarter-based derivation remains only
     * as a fallback for legacy exams created before this snapshot existed
     * (academic_year_id still null on those rows) — never the other way
     * around, and the snapshot is never inferred backward onto a legacy
     * row by this method itself (that is an explicit, separate backfill,
     * not something resolveAcademicYear() performs as a side effect).
     */
    public function resolveAcademicYear(): ?AcademicYear
    {
        return $this->academic_year_id !== null
            ? AcademicYear::find($this->academic_year_id)
            : Quarter::find($this->quarter_id)?->resolveAcademicYear();
    }

}
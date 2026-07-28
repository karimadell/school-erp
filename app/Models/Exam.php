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
        'max_score'

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
     * Item 2, approved policy: an Exam with no quarter_id has no
     * determinable academic year and fails closed — this is not treated
     * as "unscoped/exempt" even though quarter_id remains nullable at the
     * schema level (Section 4 of OPEN_POLICY_DECISIONS.md leaves open
     * whether an exam must belong to a quarter; this lock does not answer
     * that question, it just refuses to guess).
     */
    public function resolveAcademicYear(): ?AcademicYear
    {
        return Quarter::find($this->quarter_id)?->resolveAcademicYear();
    }

}
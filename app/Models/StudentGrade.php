<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use Illuminate\Database\Eloquent\Model;

class StudentGrade extends Model implements ResolvesAcademicYear
{
    protected $fillable = [
        'student_id',
        'exam_id',
        'subject_id',
        'quarter_id',
        'score',
        'note'
    ];

    protected $casts = [
        'score' => 'float',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function quarter()
    {
        return $this->belongsTo(Quarter::class);
    }

    /**
     * Item 2, approved policy: prefers the grade's own quarter_id; falls
     * back to the linked exam's quarter. If neither resolves to a year
     * (both exam_id and quarter_id absent, or the exam itself has no
     * quarter), this fails closed rather than being treated as exempt.
     */
    public function resolveAcademicYear(): ?AcademicYear
    {
        return Quarter::find($this->quarter_id)?->resolveAcademicYear()
            ?? Exam::find($this->exam_id)?->resolveAcademicYear();
    }

    /*
    |--------------------------------------------------------------------------
    | Russian Grade System
    |--------------------------------------------------------------------------
    */

    public function getRussianGradeAttribute()
    {
        $score = $this->score;

        if ($score >= 90) return 5;
        if ($score >= 75) return 4;
        if ($score >= 60) return 3;

        return 2;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper (Display)
    |--------------------------------------------------------------------------
    */

    public function getDisplayGradeAttribute()
    {
        return $this->score . ' (' . $this->russian_grade . ')';
    }
}
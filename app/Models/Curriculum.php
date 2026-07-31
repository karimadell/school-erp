<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Item 8 / C1: Academic Year x Grade x Subject, with weekly hours and a
 * mandatory/elective/optional-enrichment type. Grade-level only — no
 * class_id (Class-level override is a separate, undesigned, deferred
 * mechanism). Deliberately does not implement ResolvesAcademicYear;
 * historical-year lock integration is deferred, not part of this entity.
 */
class Curriculum extends Model
{
    protected $table = 'curricula';

    public const TYPE_MANDATORY = 'mandatory';
    public const TYPE_ELECTIVE = 'elective';
    public const TYPE_OPTIONAL_ENRICHMENT = 'optional_enrichment';

    public const ASSESSMENT_GRADE = 'grade';
    public const ASSESSMENT_PASS_FAIL = 'pass_fail';
    public const ASSESSMENT_UNGRADED = 'ungraded';

    protected $fillable = [
        'academic_year_id',
        'grade_id',
        'subject_id',
        'weekly_hours',
        'type',
        'assessment_type',
        'report_order',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'weekly_hours' => 'integer',
        'report_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Batch 11 / C2: students who have individually elected this
     * Curriculum row (elective / optional-enrichment only).
     */
    public function studentSubjectEnrollments()
    {
        return $this->hasMany(StudentSubjectEnrollment::class);
    }
}

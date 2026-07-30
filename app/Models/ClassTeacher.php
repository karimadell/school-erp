<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Batch 11 / C4: Class Teacher assignment (классный руководитель) —
 * SchoolClass x Teacher x Academic Year. See the migration's doc comment
 * for why this is a dedicated table rather than an extension of
 * TeacherAssignment. Deliberately does not implement ResolvesAcademicYear
 * — historical-year lock integration is explicitly deferred.
 */
class ClassTeacher extends Model
{
    protected $fillable = [
        'school_class_id',
        'teacher_id',
        'academic_year_id',
    ];

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentListenerPlacement extends Model
{
    protected $guarded = [];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function stage()
    {
        return $this->belongsTo(Stage::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}

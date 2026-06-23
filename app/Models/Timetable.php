<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Timetable extends Model
{
    protected $fillable = [
        'class_id',
        'subject_id',
        'teacher_id',
        'day_id',
        'period_id',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    // Timetable → Class
    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    // Timetable → Subject
    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    // Timetable → Teacher
    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    // Timetable → Day
    public function day()
    {
        return $this->belongsTo(Day::class);
    }

    // Timetable → Period
    public function period()
    {
        return $this->belongsTo(Period::class);
    }
}
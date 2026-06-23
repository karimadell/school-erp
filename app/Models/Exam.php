<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{

    protected $fillable = [

        'name',
        'subject_id',
        'class_id',
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

    public function grades()
    {
        return $this->hasMany(StudentGrade::class);
    }

}
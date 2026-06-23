<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentFile extends Model
{
    protected $fillable = [
        'student_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'type',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
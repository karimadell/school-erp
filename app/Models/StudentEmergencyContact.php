<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentEmergencyContact extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}

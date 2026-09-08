<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterStudentImport extends Model
{
    protected $guarded = [];

    protected $casts = ['source_data' => 'array'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function listenerPlacement()
    {
        return $this->belongsTo(StudentListenerPlacement::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $fillable = [
        'enrollment_id',
        'period_id',
        'date',
        'type',
        'status',
        'note',
        'attendance_key',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentBootstrapImport extends Model
{
    protected $fillable = [
        'source_key', 'source_file', 'source_sheet', 'source_row', 'raw_full_name',
        'raw_class', 'raw_pickup_point', 'raw_contact', 'route', 'student_id', 'enrollment_id',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }
}

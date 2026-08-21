<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentEducationalNeed extends Model
{
    protected $guarded = [];

    protected $casts = [
        'has_ovz' => 'boolean',
        'has_disability' => 'boolean',
        'requires_adapted_program' => 'boolean',
        'requires_special_conditions' => 'boolean',
        'consent_received_at' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}

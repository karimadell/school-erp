<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentRepresentative extends Model
{
    use SoftDeletes;

    public const RELATIONSHIPS = ['father', 'mother', 'guardian', 'custodian', 'other'];

    protected $guarded = [];

    protected $casts = [
        'is_legal_representative' => 'boolean',
        'is_primary_contact' => 'boolean',
        'has_guardianship_authority' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function files()
    {
        return $this->hasMany(StudentFile::class);
    }
}

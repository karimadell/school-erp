<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnrollmentMode extends Model
{
    public const REGULAR = 'regular';
    public const DISTANCE_LEARNING = 'distance_learning';

    protected $fillable = [
        'code',
        'name_ru',
        'name_ar',
        'name_en',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }
}

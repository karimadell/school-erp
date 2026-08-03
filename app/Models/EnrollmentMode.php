<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnrollmentMode extends Model
{
    public const FULL_TIME = 'full_time';
    public const REGULAR = 'regular';
    public const DISTANCE_LEARNING = 'distance_learning';

    protected $fillable = [
        'code',
        'name_ru',
        'short_name_ru',
        'name_ar',
        'name_en',
        'is_active',
        'display_order',
        'description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('id');
    }
}

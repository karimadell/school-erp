<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassRoom extends Model
{
    protected $fillable = [
        'grade_id',
        'code',
        'name_ar',
        'name_ru',
        'capacity',
        'is_active',
    ];

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }
}
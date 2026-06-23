<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $fillable = [
        'code',
        'name_ru',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function teachers()
    {
        return $this->belongsToMany(
            Teacher::class,
            'teacher_subject',
            'subject_id',
            'teacher_id'
        );
    }

    public function timetables()
    {
        return $this->hasMany(Timetable::class, 'subject_id');
    }

    public function getNameAttribute()
    {
        return $this->name_ru;
    }
}
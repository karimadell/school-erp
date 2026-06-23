<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    protected $table = 'teachers';

    protected $fillable = [
        'first_name',
        'patronymic',
        'last_name',
        'phone',
        'email',
        'specialization',
        'hire_date',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'hire_date' => 'date',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function subjects()
    {
        return $this->belongsToMany(
            Subject::class,
            'teacher_subject',
            'teacher_id',
            'subject_id'
        );
    }

    public function documents()
    {
        return $this->hasMany(TeacherDocument::class, 'teacher_id');
    }

    public function timetables()
    {
        return $this->hasMany(Timetable::class, 'teacher_id');
    }

    public function classes()
    {
        return $this->hasMany(SchoolClass::class, 'teacher_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function getFullNameAttribute()
    {
        return trim(
            ($this->last_name ?? '') . ' ' .
            ($this->first_name ?? '') . ' ' .
            ($this->patronymic ?? '')
        );
    }

    public function getShortNameAttribute()
    {
        $first = $this->first_name
            ? mb_substr($this->first_name, 0, 1) . '.'
            : '';

        $patronymic = $this->patronymic
            ? mb_substr($this->patronymic, 0, 1) . '.'
            : '';

        return trim(($this->last_name ?? '') . ' ' . $first . ' ' . $patronymic);
    }

    public function getStatusLabelAttribute()
    {
        return $this->is_active
            ? __('teachers.active')
            : __('teachers.inactive');
    }
}
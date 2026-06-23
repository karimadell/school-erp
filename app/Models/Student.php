<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\SchoolClass;

class Student extends Model
{
    protected $fillable = [
        
        'academic_year',
        

        'class_id',
        'first_name',
        'last_name',
        'first_name_ru',
        'last_name_ru',
        'patronymic_ru',
        'birth_date',
        'gender',
        'phone',
        'email',
        'nationality',
        'address',
        'photo',
        'documents',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'documents' => 'array',
    ];

    public function class()
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function currentEnrollment()
    {
        return $this->hasOne(Enrollment::class)->where('is_active', true);
    }

    public function attendances()
    {
        return $this->hasManyThrough(
            Attendance::class,
            Enrollment::class,
            'student_id',
            'enrollment_id',
            'id',
            'id'
        );
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function grades()
    {
        return $this->hasMany(StudentGrade::class);
    }

    public function getFullNameAttribute()
    {
        if (! empty($this->name)) {
            return $this->name;
        }

        $parts = array_filter([
            $this->last_name_ru ?: $this->last_name,
            $this->first_name_ru ?: $this->first_name,
            $this->patronymic_ru,
        ]);

        return implode(' ', $parts);
    }

    public function getShortNameAttribute()
    {
        if (! empty($this->name)) {
            return $this->name;
        }

        $last = $this->last_name_ru ?: $this->last_name;

        $firstInitial = $this->first_name_ru
            ? mb_substr($this->first_name_ru, 0, 1) . '.'
            : ($this->first_name ? mb_substr($this->first_name, 0, 1) . '.' : '');

        $patronymicInitial = $this->patronymic_ru
            ? mb_substr($this->patronymic_ru, 0, 1) . '.'
            : '';

        return trim($last . ' ' . $firstInitial . $patronymicInitial);
    }

    public function averageGrade()
    {
        return round($this->grades()->avg('score') ?? 0, 2);
    }

    public function yearGrade($subjectId)
    {
        return round(
            $this->grades()
                ->where('subject_id', $subjectId)
                ->avg('score') ?? 0,
            2
        );
    }
}
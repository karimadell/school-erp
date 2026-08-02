<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stage extends Model
{
    protected $fillable = [
        'name',
        'description',
        'order',
        'is_active',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    // Stage → Grades
    public function grades()
    {
        return $this->hasMany(Grade::class)
            ->orderByRaw('CASE WHEN level IS NULL THEN 1 ELSE 0 END')
            ->orderBy('level')
            ->orderBy('id');
    }

    // Stage → Classes (through grades)
    public function classes()
    {
        return $this->hasManyThrough(
            SchoolClass::class,
            Grade::class,
            'stage_id',   // Foreign key on grades
            'grade_id',   // Foreign key on classes
            'id',
            'id'
        );
    }

    // Stage → Students (through classes)
    public function students()
    {
        return $this->hasManyThrough(
            Student::class,
            SchoolClass::class,
            'grade_id',
            'class_id',
            'id',
            'id'
        );
    }
}

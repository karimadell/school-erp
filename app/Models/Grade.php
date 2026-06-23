<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    protected $fillable = [
        'name',
        'stage_id',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    // Grade → Stage
    public function stage()
    {
        return $this->belongsTo(Stage::class);
    }

    // Grade → Classes
    public function classes()
    {
        return $this->hasMany(SchoolClass::class, 'grade_id')
            ->orderBy('code');
    }

    // Grade → Students (through classes)
    public function students()
    {
        return $this->hasManyThrough(
            Student::class,
            SchoolClass::class,
            'grade_id',   // foreign key on classes
            'class_id',   // foreign key on students
            'id',
            'id'
        );
    }

}
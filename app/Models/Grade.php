<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Grade extends Model
{
    protected $fillable = [
        'name',
        'stage_id',
    ];

    protected function casts(): array
    {
        return ['level' => 'integer'];
    }

    /** Apply the canonical numeric school-grade order. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN grades.level IS NULL THEN 1 ELSE 0 END')
            ->orderBy('grades.level')
            ->orderBy('grades.id');
    }

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

    // Grade → Curricula
    public function curricula()
    {
        return $this->hasMany(Curriculum::class);
    }

}

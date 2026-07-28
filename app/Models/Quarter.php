<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use Illuminate\Database\Eloquent\Model;

class Quarter extends Model implements ResolvesAcademicYear
{

    protected $fillable = [
        'academic_year_id',
        'name',
        'order',
        'start_date',
        'end_date'
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function grades()
    {
        return $this->hasMany(StudentGrade::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function resolveAcademicYear(): ?AcademicYear
    {
        return AcademicYear::find($this->academic_year_id);
    }

}
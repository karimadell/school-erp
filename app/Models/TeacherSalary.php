<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherSalary extends Model
{

    protected $fillable = [

        'teacher_id',
        'base_salary',
        'bonus',
        'deductions',
        'net_salary',
        'salary_month'

    ];

    protected $casts = [

        'salary_month' => 'date',

    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Calculate Net Salary
    |--------------------------------------------------------------------------
    */

    public function calculateNet()
    {
        return ($this->base_salary ?? 0)
            + ($this->bonus ?? 0)
            - ($this->deductions ?? 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Auto calculate before saving
    |--------------------------------------------------------------------------
    */

    protected static function booted()
    {
        static::saving(function ($salary) {

            $salary->net_salary =
                ($salary->base_salary ?? 0)
                + ($salary->bonus ?? 0)
                - ($salary->deductions ?? 0);

        });
    }

}
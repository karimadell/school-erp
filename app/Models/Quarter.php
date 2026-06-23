<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quarter extends Model
{

    protected $fillable = [
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

}
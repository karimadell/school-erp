<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealSubscription extends Model
{
    protected $fillable = [
        'enrollment_id',
        'meal_plan_id',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function mealPlan()
    {
        return $this->belongsTo(MealPlan::class);
    }

    public function isActiveOn($date = null): bool
    {
        $date = $date ? \Illuminate\Support\Carbon::parse($date) : now();

        return $this->start_date->lte($date) && (! $this->end_date || $this->end_date->gte($date));
    }
}

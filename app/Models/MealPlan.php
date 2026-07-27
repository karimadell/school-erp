<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Builds the missing model layer on top of the meal_plans table, which
 * existed only as a migration (no model, no controller, no consumer)
 * before Batch 5.
 */
class MealPlan extends Model
{
    public const TYPE_BREAKFAST = 'breakfast';
    public const TYPE_LUNCH = 'lunch';
    public const TYPE_BOTH = 'both';

    public const PERIOD_DAILY = 'daily';
    public const PERIOD_WEEKLY = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';
    public const PERIOD_YEARLY = 'yearly';

    protected $fillable = [
        'name_ru',
        'name_ar',
        'meal_type',
        'period',
        'price',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function subscriptions()
    {
        return $this->hasMany(MealSubscription::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

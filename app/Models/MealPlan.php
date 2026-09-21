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

    /**
     * Active MealPlans actually priced (a `daily` FeePrice exists for
     * them) — the only plans safe to offer anywhere a Food purchase is
     * made. Extracted from FinanceOperationsController::chargeCreate()'s
     * own filter (kept byte-identical there) so the Stolovaya screen
     * shares this exact read-only list-building query instead of
     * reimplementing it. Never a pricing decision itself — FeePrice
     * remains the sole authority on price; this only decides which
     * plans are *offered* at all.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function sellableFood(): \Illuminate\Database\Eloquent\Collection
    {
        $sellableIds = Fee::active()->where('category', Fee::CATEGORY_FOOD)->with('prices')->get()
            ->flatMap(fn (Fee $fee) => $fee->prices)
            ->where('payment_period', Fee::PERIOD_DAILY)
            ->pluck('option_value')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->unique();

        return static::active()->whereIn('id', $sellableIds)->orderBy('name_ru')->get();
    }
}

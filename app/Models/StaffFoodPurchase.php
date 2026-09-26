<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Stolovaya Phase 2 (Employee cash purchases) — the small, dedicated
 * structured record RevenueEntry alone cannot hold: employee identity,
 * meal identity, quantity, and a unit-price snapshot. One row per
 * submitted purchase (no coverage-period/overlap concept — see
 * EmployeeFoodPurchaseService's own docblock for why the Student
 * ServiceCoverage overlap rule does not apply here), one-to-one with the
 * RevenueEntry it caused.
 *
 * Immutable once created — same precedent as ServiceCoverage: this is a
 * financial/audit snapshot, not an editable record. There is no
 * StaffFoodPurchase-level reversal workflow; reversing the money remains
 * entirely RevenueService::reverse()'s job (against the linked
 * RevenueEntry), unchanged.
 */
class StaffFoodPurchase extends Model
{
    protected $fillable = [
        'revenue_entry_id',
        'employee_user_id',
        'fee_id',
        'fee_price_id',
        'meal_plan_id',
        'option_value',
        'food_date',
        'quantity',
        'unit_price',
        'total_amount',
        'idempotency_key',
        'idempotency_hash',
        'created_by',
    ];

    protected $casts = [
        'food_date' => 'date',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Запись о покупке питания сотрудником нельзя изменить.'));
        static::deleting(fn () => throw new LogicException('Запись о покупке питания сотрудником нельзя удалить.'));
    }

    public function revenueEntry()
    {
        return $this->belongsTo(RevenueEntry::class);
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    public function fee()
    {
        return $this->belongsTo(Fee::class);
    }

    public function feePrice()
    {
        return $this->belongsTo(FeePrice::class);
    }

    public function mealPlan()
    {
        return $this->belongsTo(MealPlan::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

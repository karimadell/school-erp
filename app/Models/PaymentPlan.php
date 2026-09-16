<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentPlan extends Model
{
    protected $fillable = ['name_ru', 'description', 'is_active', 'sort_order', 'is_test_data'];
    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer', 'is_test_data' => 'boolean'];

    public function installments()
    {
        return $this->hasMany(PaymentPlanInstallment::class)->orderBy('sequence');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Excludes UAT/test fixture plans (e.g. "UAT — 2 платежа 50/50",
     * created only by finance:uat-master-data-repair) from any screen an
     * accountant uses to browse or select a real plan. Purely a display-
     * scope filter — never deletes/deactivates the row, identical
     * convention to Fee::where('is_test_data', false) in Quick
     * Registration.
     */
    public function scopeNonTest($query)
    {
        return $query->where('is_test_data', false);
    }

    /** Active AND non-test — the correct scope for every operational selector. */
    public function scopeOperational($query)
    {
        return $query->active()->nonTest();
    }

    /** Finance V2, Phase 2B — Fees this plan is explicitly assigned to. */
    public function fees()
    {
        return $this->belongsToMany(Fee::class, 'fee_payment_plan');
    }
}

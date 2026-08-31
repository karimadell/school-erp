<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentPlan extends Model
{
    protected $fillable = ['name_ru', 'description', 'is_active', 'sort_order'];
    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function installments()
    {
        return $this->hasMany(PaymentPlanInstallment::class)->orderBy('sequence');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Finance V2, Phase 2B — Fees this plan is explicitly assigned to. */
    public function fees()
    {
        return $this->belongsToMany(Fee::class, 'fee_payment_plan');
    }
}

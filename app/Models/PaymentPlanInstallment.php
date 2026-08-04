<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentPlanInstallment extends Model
{
    protected $fillable = ['payment_plan_id', 'name_ru', 'sequence', 'offset_days', 'percentage'];
    protected $casts = ['sequence' => 'integer', 'offset_days' => 'integer', 'percentage' => 'decimal:4'];

    public function plan()
    {
        return $this->belongsTo(PaymentPlan::class, 'payment_plan_id');
    }
}

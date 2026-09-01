<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceInstallment extends Model
{
    public const STATUS_FUTURE = 'future';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';

    protected $fillable = ['invoice_id', 'payment_plan_id', 'name_ru', 'sequence', 'due_date', 'amount', 'paid_amount', 'remaining_amount', 'status'];
    protected $casts = ['due_date' => 'date', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'remaining_amount' => 'decimal:2', 'sequence' => 'integer'];

    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function plan() { return $this->belongsTo(PaymentPlan::class, 'payment_plan_id'); }
    public function payments() { return $this->hasMany(InvoicePayment::class); }
    public function refunds() { return $this->hasMany(PaymentRefund::class); }
    /** Finance V2, Phase 2D — which calendar period(s)/Fee coverage(s) this installment represents, if any (calendar billing only; one row per bundled periodic Fee sharing this installment). */
    public function coveragePeriods() { return $this->hasMany(InstallmentCoveragePeriod::class); }

    public function derivedStatus($asOf = null): string
    {
        $asOf ??= today();
        if (bccomp((string) $this->remaining_amount, '0.00', 2) <= 0) return self::STATUS_PAID;
        if (bccomp((string) $this->paid_amount, '0.00', 2) > 0) return self::STATUS_PARTIAL;
        if ($this->due_date->isAfter($asOf)) return self::STATUS_FUTURE;
        if ($this->due_date->isBefore($asOf)) return self::STATUS_OVERDUE;
        return self::STATUS_PENDING;
    }

    public function statusLabel($asOf = null): string
    {
        return [self::STATUS_FUTURE=>'Не наступил срок', self::STATUS_PENDING=>'Ожидает оплаты', self::STATUS_PARTIAL=>'Частично оплачен', self::STATUS_PAID=>'Оплачен', self::STATUS_OVERDUE=>'Просрочен'][$this->derivedStatus($asOf)];
    }

    public function refreshStatus(): void
    {
        // Net of refunds allocated to this installment so a reversal restores
        // the installment's outstanding amount.
        $gross = bcadd((string) $this->payments()->sum('amount'), '0', 2);
        $refunded = bcadd((string) $this->refunds()->sum('amount'), '0', 2);
        $paid = bcsub($gross, $refunded, 2);
        $this->forceFill(['paid_amount'=>$paid, 'remaining_amount'=>bcsub((string)$this->amount,$paid,2)])->saveQuietly();
        $this->forceFill(['status'=>$this->derivedStatus()])->saveQuietly();
    }
}

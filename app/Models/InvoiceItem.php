<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $attributes = ['is_non_refundable' => false];

    /**
     * الحقول القابلة للـ mass assignment
     */
    protected $fillable = [
        'invoice_id',
        'fee_id',
        'subscription_id',
        'description',
        'amount',
        'unit_price',
        'quantity',
        'paid_amount',
        'remaining_amount',
        'is_non_refundable',
        'metadata',
    ];

    /**
     * تحويل نوع المبلغ
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'is_non_refundable' => 'boolean',
        'metadata' => 'array',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * الفاتورة التابعة لها
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * نوع الرسوم (مصروفات / زي / مطعم ...)
     */
    public function fee()
    {
        return $this->belongsTo(Fee::class);
    }

    public function subscription()
    {
        return $this->belongsTo(StudentServiceSubscription::class, 'subscription_id');
    }
}

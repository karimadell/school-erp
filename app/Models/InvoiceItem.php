<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasFactory;

    /**
     * الحقول القابلة للـ mass assignment
     */
    protected $fillable = [
        'invoice_id',
        'fee_id',
        'description',
        'amount',
    ];

    /**
     * تحويل نوع المبلغ
     */
    protected $casts = [
        'amount' => 'decimal:2',
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
}
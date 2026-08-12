<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CashTransaction extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Transaction Types
    |--------------------------------------------------------------------------
    */

    const TYPE_IN  = 'in';
    const TYPE_OUT = 'out';

    /*
    |--------------------------------------------------------------------------
    | Categories (🔥 مهم للتقارير)
    |--------------------------------------------------------------------------
    */

    const CATEGORY_INCOME   = 'income';
    const CATEGORY_EXPENSE  = 'expense';
    const CATEGORY_TRANSFER = 'transfer';

    /*
    |--------------------------------------------------------------------------
    | Payment Methods
    |--------------------------------------------------------------------------
    */

    const METHOD_CASH     = 'cash';
    const METHOD_CARD     = 'card';
    const METHOD_BANK     = 'bank';
    const METHOD_TRANSFER = 'transfer';

    /*
    |--------------------------------------------------------------------------
    | Fillable
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'cash_account_id',
        'cash_session_id', // Phase 3: owning cash-drawer session (nullable)
        'created_by',      // Phase 3: cashier who posted the movement (nullable)
        'invoice_id',
        'invoice_payment_id',
        'amount',
        'type',
        'category', // 🔥 جديد
        'payment_method',
        'description',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function account()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    // Phase 3: owning cash-drawer session (nullable — set at creation for new
    // cash movements, null for non-cash and historical rows).
    public function cashSession()
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    // Phase 3: the user who posted the movement (nullable for historical rows).
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoicePayment()
    {
        return $this->belongsTo(InvoicePayment::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeIn($query)
    {
        return $query->where('type', self::TYPE_IN);
    }

    public function scopeOut($query)
    {
        return $query->where('type', self::TYPE_OUT);
    }

    public function scopeCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isIn()
    {
        return $this->type === self::TYPE_IN;
    }

    public function isOut()
    {
        return $this->type === self::TYPE_OUT;
    }

    public function isTransfer()
    {
        return $this->category === self::CATEGORY_TRANSFER;
    }

    /*
    |--------------------------------------------------------------------------
    | Events (Auto Balance Update)
    |--------------------------------------------------------------------------
    */

    protected static function booted()
    {
        // إنشاء
        static::created(function ($transaction) {

            $account = $transaction->account;

            if (!$account) return;

            if ($transaction->type === self::TYPE_IN) {
                $account->increment('balance', $transaction->amount);
            }

            if ($transaction->type === self::TYPE_OUT) {
                $account->decrement('balance', $transaction->amount);
            }
        });

        // حذف
        static::deleted(function ($transaction) {

            $account = $transaction->account;

            if (!$account) return;

            if ($transaction->type === self::TYPE_IN) {
                $account->decrement('balance', $transaction->amount);
            }

            if ($transaction->type === self::TYPE_OUT) {
                $account->increment('balance', $transaction->amount);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Transfer Between Accounts (🔥 مطور)
    |--------------------------------------------------------------------------
    */

    public static function transfer($fromAccountId, $toAccountId, $amount, $description = 'Transfer')
    {
        DB::transaction(function () use ($fromAccountId, $toAccountId, $amount, $description) {

            self::create([
                'cash_account_id' => $fromAccountId,
                'amount' => $amount,
                'type' => self::TYPE_OUT,
                'category' => self::CATEGORY_TRANSFER,
                'payment_method' => self::METHOD_TRANSFER,
                'description' => $description . ' (From Account)'
            ]);

            self::create([
                'cash_account_id' => $toAccountId,
                'amount' => $amount,
                'type' => self::TYPE_IN,
                'category' => self::CATEGORY_TRANSFER,
                'payment_method' => self::METHOD_TRANSFER,
                'description' => $description . ' (To Account)'
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Helper: Create Debt Payment
    |--------------------------------------------------------------------------
    */

    public static function createDebtPayment($invoice, $amount, $accountId = 1)
    {
        return self::create([
            'cash_account_id' => $accountId,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'type' => self::TYPE_IN,
            'category' => self::CATEGORY_INCOME,
            'payment_method' => self::METHOD_CASH,
            'description' => 'Debt Payment - Student ID: ' . $invoice->student_id
        ]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashAccount extends Model
{
    protected $table = 'cash_accounts';

    /*
    |--------------------------------------------------------------------------
    | Account Types
    |--------------------------------------------------------------------------
    */

    const TYPE_CASH = 'cash';
    const TYPE_BANK = 'bank';

    /*
    |--------------------------------------------------------------------------
    | Fillable
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'name',
        'type',
        'balance',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    // الحساب ← الفواتير
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    // الحساب ← المعاملات المالية
    public function transactions()
    {
        return $this->hasMany(CashTransaction::class);
    }
}
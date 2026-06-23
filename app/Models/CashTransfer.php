<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'receipt_number',
        'from_account_id',
        'to_account_id',
        'amount',
        'notes',
        'transfer_date',
        'created_by'
    ];

    public function fromAccount()
    {
        return $this->belongsTo(CashAccount::class, 'from_account_id');
    }

    public function toAccount()
    {
        return $this->belongsTo(CashAccount::class, 'to_account_id');
    }

}
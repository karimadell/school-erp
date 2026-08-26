<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashTransfer extends Model
{
    use HasFactory;

    // Cash Operations Phase 1 classification (see the migration adding
    // transfer_type for the full rationale). 'internal' is the default,
    // pre-existing generic transfer; the other two back the dedicated
    // daily-handover / owner-return workflows.
    const TYPE_INTERNAL = 'internal';

    const TYPE_HANDOVER = 'handover';

    const TYPE_OWNER_RETURN = 'owner_return';

    protected $fillable = [
        'receipt_number',
        'from_account_id',
        'to_account_id',
        'amount',
        'notes',
        'transfer_date',
        'created_by',
        'transfer_type',
        'idempotency_key',
        'idempotency_hash',
    ];

    public function fromAccount()
    {
        return $this->belongsTo(CashAccount::class, 'from_account_id');
    }

    public function toAccount()
    {
        return $this->belongsTo(CashAccount::class, 'to_account_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

}
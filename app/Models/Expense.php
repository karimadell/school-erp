<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{

    protected $fillable = [

        'title',
        'amount',
        'category',
        'description',
        'expense_date',
        'cash_account_id'

    ];

    protected $casts = [

        'expense_date' => 'date'

    ];

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Automatic Cash Transaction
    |--------------------------------------------------------------------------
    */

    protected static function booted()
    {

        static::created(function ($expense) {

            // Phase 0 safety fix: the previous hook wrote type='expense', which
            // is not a valid cash_transactions.type ('in','out') and was
            // ignored by the balance hook, so expenses never reduced the cash
            // account. Post a valid outgoing, expense-category transaction so
            // the linked balance is decremented exactly once.
            //
            // Cash Operations Phase 1: attribute the movement to the
            // currently open shift on this drawer, if any, so cash expenses
            // correctly count against that session's expected closing cash
            // (CashSession::cashOut()) — the same "- Cash Expenses" term the
            // reconciliation formula already expects. Best-effort only: no
            // open shift is required to record an expense (unchanged
            // behaviour), it just won't be attributed to any session.
            $account = $expense->cashAccount;
            $cashSessionId = $account && $account->isCashDrawer()
                ? app(\App\Services\Finance\CashSessionService::class)->activeFor($account)?->id
                : null;

            CashTransaction::create([

                'type' => CashTransaction::TYPE_OUT,

                'category' => CashTransaction::CATEGORY_EXPENSE,

                'amount' => $expense->amount,

                'cash_account_id' => $expense->cash_account_id,

                'cash_session_id' => $cashSessionId,

                'created_by' => auth()->id(),

                'description' => 'Расход: ' . $expense->title

            ]);

        });

    }

}
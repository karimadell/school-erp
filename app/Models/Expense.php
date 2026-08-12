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
            CashTransaction::create([

                'type' => CashTransaction::TYPE_OUT,

                'category' => CashTransaction::CATEGORY_EXPENSE,

                'amount' => $expense->amount,

                'cash_account_id' => $expense->cash_account_id,

                'description' => 'Расход: ' . $expense->title

            ]);

        });

    }

}
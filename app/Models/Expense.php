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

            CashTransaction::create([

                'type' => 'expense',

                'amount' => -$expense->amount,

                'cash_account_id' => $expense->cash_account_id,

                'description' => 'Expense: ' . $expense->title

            ]);

        });

    }

}
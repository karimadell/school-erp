<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CashTransaction;
use App\Models\CashAccount;

class CashController extends Controller
{
    public function storeExpense(Request $request)
    {
        CashTransaction::create([
            'cash_account_id' => $request->cash_account_id,
            'type' => 'out',
            'amount' => $request->amount,
            'notes' => $request->notes
        ]);

        $account = CashAccount::findOrFail($request->cash_account_id);

        if ($account->balance < $request->amount) {
            return redirect()->back()->with('error','Insufficient balance');
        }

        $account->balance -= $request->amount;
        $account->save();

        return redirect()->back()->with('success','Expense added');
    }
}
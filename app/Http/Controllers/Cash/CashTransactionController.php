<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CashAccount;
use App\Models\CashTransaction;

class CashTransactionController extends Controller
{
    public function income()
    {
        $accounts = CashAccount::orderBy('name')->get();

        return view('dashboard.cash.income', compact('accounts'));
    }

    public function expenses()
    {
        $accounts = CashAccount::orderBy('name')->get();

        return view('dashboard.cash.expenses', compact('accounts'));
    }

    public function storeIncome(Request $request)
    {
        CashTransaction::create([
            'cash_account_id' => $request->cash_account_id,
            'type' => 'in',
            'amount' => $request->amount,
            'notes' => $request->notes,
        ]);

        return redirect()->back()->with('success', 'Income added');
    }

    public function storeExpense(Request $request)
    {
        CashTransaction::create([
            'cash_account_id' => $request->cash_account_id,
            'type' => 'out',
            'amount' => $request->amount,
            'notes' => $request->notes,
        ]);

        return redirect()->back()->with('success', 'Expense added');
    }

    public function reports(Request $request)
    {

        $query = CashTransaction::with('account');

        // ===== Filter by type =====
        if ($request->type) {
            $query->where('type', $request->type);
        }

        // ===== Filter by date range =====
        if ($request->from_date) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // ===== Transactions =====
        $transactions = $query
            ->latest()
            ->paginate(20)
            ->withQueryString();

        // ===== Chart Data =====
        $chartQuery = CashTransaction::query();

        if ($request->type) {
            $chartQuery->where('type', $request->type);
        }

        if ($request->from_date) {
            $chartQuery->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $chartQuery->whereDate('created_at', '<=', $request->to_date);
        }

        $chartData = $chartQuery->selectRaw("
            DATE(created_at) as date,
            SUM(CASE WHEN type='in' THEN amount ELSE 0 END) as income,
            SUM(CASE WHEN type='out' THEN amount ELSE 0 END) as expenses
        ")
        ->groupBy('date')
        ->orderBy('date')
        ->get();

        $chartDates = $chartData->pluck('date');
        $chartIn = $chartData->pluck('income');
        $chartOut = $chartData->pluck('expenses');

        return view('dashboard.cash.reports', compact(
            'transactions',
            'chartDates',
            'chartIn',
            'chartOut'
        ));
    }
}
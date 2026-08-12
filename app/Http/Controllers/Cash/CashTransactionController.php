<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CashAccount;
use App\Models\CashTransaction;

class CashTransactionController extends Controller
{
    public function __construct()
    {
        // Phase 0 safety lockdown: every cash action is gated. This module has
        // no separate read-only cash permission, so reads fall back to the same
        // 'manage cash' gate.
        $this->middleware('permission:manage cash');
    }

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

    // Phase 0 safety lockdown: raw cash-in bypassed the invoice/payment
    // services and created untraceable (orphan) money. Income must be recorded
    // only through InvoicePaymentService against a real invoice.
    public function storeIncome(Request $request)
    {
        abort(410, 'Прямое внесение прихода отключено. Приход регистрируется только оплатой счёта.');
    }

    // Phase 0 safety lockdown: raw cash-out bypassed the Expense document.
    // Expenses must be recorded through the Expense workflow, which posts a
    // linked cash transaction.
    public function storeExpense(Request $request)
    {
        abort(410, 'Прямое списание расхода отключено. Используйте оформление расхода.');
    }

    // Phase 0 safety lockdown: these routes referenced undefined actions and
    // could never post a valid transaction. Neutralised so they return a clean
    // response instead of a runtime error, and cannot insert raw cash.
    public function index(Request $request)
    {
        abort(410, 'Прямое движение по кассе отключено. Используйте оплату счетов и оформление расходов.');
    }

    public function storeIn(Request $request)
    {
        abort(410, 'Прямое внесение прихода отключено. Приход регистрируется только оплатой счёта.');
    }

    public function storeOut(Request $request)
    {
        abort(410, 'Прямое списание расхода отключено. Используйте оформление расхода.');
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

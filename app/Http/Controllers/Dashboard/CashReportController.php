<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use Illuminate\Http\Request;

class CashReportController extends Controller
{
    public function index(Request $request)
    {
        $accounts = CashAccount::orderBy('name')->get();

        $accountId = $request->account_id;
        $from = $request->from_date;
        $to = $request->to_date;

        $transactions = CashTransaction::query();

        if ($accountId) {
            $transactions->where('cash_account_id', $accountId);
        }

        if ($from) {
            $transactions->whereDate('created_at', '>=', $from);
        }

        if ($to) {
            $transactions->whereDate('created_at', '<=', $to);
        }

        $transactions = $transactions->orderBy('created_at')->get();

        // 💰 رصيد أول المدة
        $openingBalance = 0;

        if ($accountId && $from) {
            $openingBalance = CashTransaction::where('cash_account_id', $accountId)
                ->whereDate('created_at', '<', $from)
                ->selectRaw("
                    SUM(CASE WHEN type = 'in' THEN amount ELSE 0 END) -
                    SUM(CASE WHEN type = 'out' THEN amount ELSE 0 END)
                as balance
                ")
                ->value('balance') ?? 0;
        }

        // 📊 الإجماليات
        $totalIn = $transactions->where('type', 'in')->sum('amount');
        $totalOut = $transactions->where('type', 'out')->sum('amount');

        $net = $totalIn - $totalOut;
        $closingBalance = $openingBalance + $net;

        return view('dashboard.cash.reports.index', compact(
            'accounts',
            'transactions',
            'totalIn',
            'totalOut',
            'net',
            'openingBalance',
            'closingBalance',
            'accountId',
            'from',
            'to'
        ));
    }
}
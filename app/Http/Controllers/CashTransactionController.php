<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

/* Export */
use App\Exports\CashReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class CashTransactionController extends Controller
{
    /**
     * List cash transactions
     */
    public function index(): View
    {
        $accounts = CashAccount::orderBy('name')->get();

        $transactions = CashTransaction::with('cashAccount')
            ->latest()
            ->paginate(20);

        return view('dashboard.cash.transactions.index', compact(
            'accounts',
            'transactions'
        ));
    }

    /**
     * Add cash (IN)
     */
    public function storeIn(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cash_account_id' => ['required', 'exists:cash_accounts,id'],
            'amount'          => ['required', 'numeric', 'min:1'],
            'description'     => ['nullable', 'string', 'max:255'],
        ]);

        $account = CashAccount::lockForUpdate()
            ->findOrFail($data['cash_account_id']);

        $account->increment('balance', $data['amount']);

        CashTransaction::create([
            'cash_account_id' => $account->id,
            'amount'          => $data['amount'],
            'type'            => 'in',
            'description'     => $data['description'] ?? 'Manual cash in',
        ]);

        return back()->with('success', 'تمت إضافة المبلغ بنجاح');
    }

    /**
     * Remove cash (OUT)
     */
    public function storeOut(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cash_account_id' => ['required', 'exists:cash_accounts,id'],
            'amount'          => ['required', 'numeric', 'min:1'],
            'description'     => ['nullable', 'string', 'max:255'],
        ]);

        $account = CashAccount::lockForUpdate()
            ->findOrFail($data['cash_account_id']);

        if ($account->balance < $data['amount']) {
            return back()->with('error', 'الرصيد غير كافٍ');
        }

        $account->decrement('balance', $data['amount']);

        CashTransaction::create([
            'cash_account_id' => $account->id,
            'amount'          => $data['amount'],
            'type'            => 'out',
            'description'     => $data['description'] ?? 'Manual cash out',
        ]);

        return back()->with('success', 'تم خصم المبلغ بنجاح');
    }

    /**
     * Cash Reports (Dashboard view)
     */
    public function reports(Request $request): View
    {
        // 📝 Audit Log
        AuditLog::create([
            'user_id'  => auth()->id(),
            'action'   => 'view cash reports',
            'model'    => 'CashTransaction',
            'model_id' => null,
            'ip'       => $request->ip(),
        ]);

        /* ===============================
         | Latest Transactions
         |===============================*/
        $transactions = CashTransaction::with('cashAccount')
            ->latest()
            ->limit(50)
            ->get();

        /* ===============================
         | Totals
         |===============================*/
        $totalIn = CashTransaction::where('type', 'in')
            ->sum('amount');

        $totalOut = CashTransaction::where('type', 'out')
            ->sum('amount');

        /* ===============================
         | Chart Data
         |===============================*/
        $daily = CashTransaction::selectRaw('DATE(created_at) as date, type, SUM(amount) as total')
            ->groupBy('date', 'type')
            ->orderBy('date')
            ->get()
            ->groupBy('type');

        $chartDates = collect($daily)->first()?->pluck('date') ?? [];
        $chartIn = $daily['in']?->pluck('total') ?? [];
        $chartOut = $daily['out']?->pluck('total') ?? [];

        /* ===============================
         | Daily / Monthly for Export
         |===============================*/
        $date  = $request->get('date', now()->toDateString());
        $month = $request->get('month', now()->format('Y-m'));

        $dailyTransactions = CashTransaction::with('cashAccount')
            ->whereDate('created_at', $date)
            ->orderBy('created_at')
            ->get();

        $monthlyTransactions = CashTransaction::with('cashAccount')
            ->whereMonth('created_at', substr($month, 5, 2))
            ->whereYear('created_at', substr($month, 0, 4))
            ->orderBy('created_at')
            ->get();

        return view('dashboard.cash.reports', compact(
            'transactions',
            'totalIn',
            'totalOut',
            'chartDates',
            'chartIn',
            'chartOut',
            'dailyTransactions',
            'monthlyTransactions',
            'date',
            'month'
        ));
    }

    /**
     * Export Excel
     */
    public function exportExcel(Request $request)
    {
        AuditLog::create([
            'user_id'  => auth()->id(),
            'action'   => 'export cash reports (excel)',
            'model'    => 'CashTransaction',
            'model_id' => null,
            'details'  => "Type: {$request->get('type')} | Value: {$request->get('value')}",
            'ip'       => $request->ip(),
        ]);

        return Excel::download(
            new CashReportExport(
                $request->get('type', 'daily'),
                $request->get('value', now()->toDateString())
            ),
            'cash-report.xlsx'
        );
    }

    /**
     * Export PDF
     */
    public function exportPdf(Request $request)
    {
        $date  = $request->get('date', now()->toDateString());
        $month = $request->get('month', now()->format('Y-m'));

        AuditLog::create([
            'user_id'  => auth()->id(),
            'action'   => 'export cash reports (pdf)',
            'model'    => 'CashTransaction',
            'model_id' => null,
            'details'  => "Date: {$date}, Month: {$month}",
            'ip'       => $request->ip(),
        ]);

        $daily = CashTransaction::with('cashAccount')
            ->whereDate('created_at', $date)
            ->orderBy('created_at')
            ->get();

        $monthly = CashTransaction::with('cashAccount')
            ->whereMonth('created_at', substr($month, 5, 2))
            ->whereYear('created_at', substr($month, 0, 4))
            ->orderBy('created_at')
            ->get();

        $pdf = Pdf::loadView('dashboard.cash.reports-pdf', compact(
            'daily',
            'monthly',
            'date',
            'month'
        ));

        return $pdf->download('cash-report.pdf');
    }
    /**
     * Show transfer page
     */
    public function transferForm()
    {
        $accounts = \App\Models\CashAccount::orderBy('name')->get();

        return view('dashboard.cash.transfer', compact('accounts'));
    }


    /**
     * Handle transfer between accounts
     */
    public function transfer(Request $request)
    {
        $data = $request->validate([
            'from_account' => ['required','exists:cash_accounts,id'],
            'to_account' => ['required','exists:cash_accounts,id'],
            'amount' => ['required','numeric','min:1'],
            'description' => ['nullable','string','max:255'],
        ]);

        if ($data['from_account'] == $data['to_account']) {
            return back()->with('error','لا يمكن التحويل لنفس الخزنة');
        }

        \App\Models\CashTransaction::transfer(
            $data['from_account'],
            $data['to_account'],
            $data['amount'],
            $data['description'] ?? 'Cash Transfer'
        );

        return back()->with('success','تم التحويل بنجاح');
    }
}
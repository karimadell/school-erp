<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\CashTransfer;
use Illuminate\Http\Request;

class CashAccountController extends Controller
{
    public function __construct()
    {
        // $this->middleware('permission:cash.view')->only(['index', 'ledger', 'accountLedger']);
        $this->middleware('permission:cash.create')->only(['create','store']);
        $this->middleware('permission:cash.edit')->only(['update']);
        $this->middleware('permission:cash.delete')->only(['destroy']);
    }

    // عرض الخزن
    public function index()
    {
        $accounts = CashAccount::with('parent')->get();

        return view('dashboard.cash.accounts.index', compact('accounts'));
    }

    // صفحة إنشاء خزنة
    public function create()
    {
        $mainAccounts = CashAccount::where('type','main')->get();

        return view('dashboard.cash.accounts.create',compact('mainAccounts'));
    }

    // إنشاء خزنة
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:main,sub',
            'parent_id' => 'nullable|exists:cash_accounts,id',
            'balance' => 'nullable|numeric'
        ]);

        $account = CashAccount::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'parent_id' => $data['parent_id'] ?? null,
            'balance' => $data['balance'] ?? 0
        ]);

        return redirect()->back()->with('success','Cash account created');
    }

    // تحديث خزنة
    public function update(Request $request, $id)
    {
        $account = CashAccount::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $account->update($data);

        return redirect()->back()->with('success','Cash account updated');
    }

    // حذف خزنة
    public function destroy($id)
    {
        CashAccount::findOrFail($id)->delete();

        return redirect()->back()->with('success','Cash account deleted');
    }

    // Ledger عام لكل العمليات
    public function ledger()
    {
        $transactions = CashTransaction::with('account')
            ->latest()
            ->paginate(20);

        return view('dashboard.cash.ledger', compact('transactions'));
    }

    // Ledger لخزنة محددة
    public function accountLedger($id)
    {
        $account = CashAccount::findOrFail($id);

        $transactions = CashTransaction::where('cash_account_id', $id)
            ->selectRaw("
                created_at as date,
                type,
                amount,
                description,
                'transaction' as source
            ");

        $transfersOut = CashTransfer::where('from_account_id', $id)
            ->selectRaw("
                created_at as date,
                'transfer_out' as type,
                amount * -1 as amount,
                description,
                'transfer' as source
            ");

        $transfersIn = CashTransfer::where('to_account_id', $id)
            ->selectRaw("
                created_at as date,
                'transfer_in' as type,
                amount,
                description,
                'transfer' as source
            ");

        $ledger = $transactions
            ->unionAll($transfersOut)
            ->unionAll($transfersIn)
            ->orderBy('date')
            ->get();

        $balance = 0;

        $ledger = $ledger->map(function ($row) use (&$balance) {
            $balance += $row->amount;
            $row->balance = $balance;
            return $row;
        });

        return response()->json([
            'account' => $account,
            'ledger'  => $ledger
        ]);
    }

    // تحويل بين الخزن
    public function transfer(Request $request)
    {
        $request->validate([
            'from_account_id' => 'required|exists:cash_accounts,id',
            'to_account_id' => 'required|exists:cash_accounts,id',
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string'
        ]);

        $from = CashAccount::findOrFail($request->from_account_id);
        $to   = CashAccount::findOrFail($request->to_account_id);

        if ($from->balance < $request->amount) {
            return back()->with('error','Insufficient balance in source account');
        }

        // خصم من الخزنة الأولى
        $from->balance -= $request->amount;
        $from->save();

        // إضافة للخزنة الثانية
        $to->balance += $request->amount;
        $to->save();

        CashTransfer::create([
            'from_account_id' => $request->from_account_id,
            'to_account_id' => $request->to_account_id,
            'amount' => $request->amount,
            'description' => $request->description
        ]);

        return back()->with('success','Transfer completed successfully');
    }
}
<?php

namespace App\Http\Controllers;

use App\Models\CashTransfer;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashTransferController extends Controller
{
    public function index()
    {
        $transfers = CashTransfer::with(['fromAccount', 'toAccount'])
            ->latest()
            ->paginate(20);

        return view('cash_transfers.index', compact('transfers'));
    }

    public function create()
    {
        $accounts = CashAccount::orderBy('name')->get();
        return view('cash_transfers.create', compact('accounts'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'from_account_id' => 'required|exists:cash_accounts,id',
            'to_account_id' => 'required|exists:cash_accounts,id|different:from_account_id',
            'amount' => 'required|numeric|min:1',
            'notes' => 'nullable|string'
        ]);

        $fromAccount = CashAccount::findOrFail($request->from_account_id);

        // 🛑 تأكد من الرصيد
        if ($fromAccount->balance < $request->amount) {
            return back()->with('error', 'رصيد الخزنة غير كافٍ')->withInput();
        }

        DB::beginTransaction();

        try {

            // 🔢 رقم الإيصال
            $receiptNumber = 'TR-' . now()->format('YmdHis');

            // 💾 حفظ التحويل
            $transfer = CashTransfer::create([
                'receipt_number' => $receiptNumber,
                'from_account_id' => $request->from_account_id,
                'to_account_id' => $request->to_account_id,
                'amount' => $request->amount,
                'transfer_date' => now(),
                'notes' => $request->notes,
                'created_by' => auth()->id()
            ]);

            // 🔻 تسجيل خروج من الخزنة الأولى
            CashTransaction::create([
                'cash_account_id' => $request->from_account_id,
                'type' => 'out',
                'category' => 'transfer',
                'amount' => $request->amount,
                'notes' => 'تحويل صادر | إيصال: ' . $transfer->receipt_number
            ]);

            // 🔺 تسجيل دخول في الخزنة الثانية
            CashTransaction::create([
                'cash_account_id' => $request->to_account_id,
                'type' => 'in',
                'category' => 'transfer',
                'amount' => $request->amount,
                'notes' => 'تحويل وارد | إيصال: ' . $transfer->receipt_number
            ]);

            DB::commit();

            return redirect()->route('cash-transfers.index')
                ->with('success', 'تم إنشاء التحويل بنجاح');

        } catch (\Exception $e) {

            DB::rollBack();

            return back()->with('error', 'حدث خطأ: ' . $e->getMessage())->withInput();
        }
    }
}
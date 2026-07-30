<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\CashTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashTransferController extends Controller
{
    public function __construct()
    {
        // Every action here reads or moves money between cash accounts —
        // gated identically to CashAccountController's own write actions
        // (permission:manage cash), since this module has no separate
        // read-only cash permission to fall back to.
        $this->middleware('permission:manage cash');
    }

    public function index()
    {
        $transfers = CashTransfer::with(['fromAccount', 'toAccount', 'creator'])
            ->latest('transfer_date')
            ->paginate(20);

        return view('dashboard.cash.transfer.index', compact('transfers'));
    }

    public function create()
    {
        $accounts = CashAccount::orderBy('type')->orderBy('name')->get();

        return view('dashboard.cash.transfer.create', compact('accounts'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'from_account_id' => ['required', 'exists:cash_accounts,id', 'different:to_account_id'],
            'to_account_id' => ['required', 'exists:cash_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transfer_date' => ['nullable', 'date'],
            'purpose' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($data) {
            $from = CashAccount::lockForUpdate()->findOrFail($data['from_account_id']);
            $to = CashAccount::lockForUpdate()->findOrFail($data['to_account_id']);

            if ((float) $from->balance < (float) $data['amount']) {
                abort(422, __('cash.insufficient_balance'));
            }

            $receiptNumber = 'TR-' . now()->format('Ymd-His');

            $transfer = CashTransfer::create([
                'receipt_number' => $receiptNumber,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $data['amount'],
                'notes' => $data['purpose'] . (($data['notes'] ?? null) ? ' - ' . $data['notes'] : ''),
                'transfer_date' => $data['transfer_date'] ?? now(),
                'created_by' => auth()->id(),
            ]);

            // Balance is adjusted exactly once per side, by CashTransaction's
            // own created-event hook (see CashTransaction::booted()) — do
            // not also mutate $from/$to->balance here, or the transfer is
            // posted twice on both accounts.
            CashTransaction::create([
                'cash_account_id' => $from->id,
                'amount' => $data['amount'],
                'type' => 'out',
                'method' => 'transfer',
                'description' => 'Transfer OUT #' . $transfer->receipt_number . ' to ' . $to->name,
            ]);

            CashTransaction::create([
                'cash_account_id' => $to->id,
                'amount' => $data['amount'],
                'type' => 'in',
                'method' => 'transfer',
                'description' => 'Transfer IN #' . $transfer->receipt_number . ' from ' . $from->name,
            ]);
        });

        return redirect()
            ->route('dashboard.cash.transfers')
            ->with('success', __('cash.transfer_success'));
    }
}
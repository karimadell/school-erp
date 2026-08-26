<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashTransfer;
use App\Services\Finance\CashTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CashTransferController extends Controller
{
    public function __construct(private CashTransferService $transfers)
    {
        // Cash Operations Phase 1: 'transfer cash' lets the accountant use
        // this form without also granting the broader account/report
        // administration 'manage cash' carries. The 'permission:' route
        // middleware alias (App\Http\Middleware\CheckPermission) checks a
        // single literal permission name with no OR syntax, so an "any
        // of" check needs this closure form instead — same pattern
        // BellScheduleController already uses for its own two-permission gate.
        $this->middleware(function (Request $request, $next) {
            abort_unless($request->user()?->hasAnyPermission(['manage cash', 'transfer cash']), 403);

            return $next($request);
        });
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

        return view('dashboard.cash.transfer.create', [
            'accounts' => $accounts,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
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
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        $this->transfers->transfer(
            fromAccountId: (int) $data['from_account_id'],
            toAccountId: (int) $data['to_account_id'],
            amount: (string) $data['amount'],
            purpose: $data['purpose'],
            notes: $data['notes'] ?? null,
            actor: $request->user(),
            idempotencyKey: $data['idempotency_key'] ?? null,
            transferDate: $data['transfer_date'] ?? null,
        );

        return redirect()
            ->route('dashboard.cash.transfers')
            ->with('success', __('cash.transfer_success'));
    }
}

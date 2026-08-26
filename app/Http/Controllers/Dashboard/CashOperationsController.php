<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\CashTransfer;
use App\Services\Finance\CashTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Cash Operations Phase 1 — the accountant-facing dashboard-native home
 * for the four canonical accounts (Операционная касса / Касса владельца /
 * Банковский счёт / InstaPay) plus the two specialised, pre-filled
 * transfer workflows the real business process needs (daily handover to
 * the owner, owner topping the operating drawer back up).
 *
 * Every mutation here is a thin call into CashTransferService — this
 * controller contains no balance math, no locking, and no idempotency
 * logic of its own. Generic account-to-account transfers and shift
 * open/close continue to live on their own existing, already-canonical
 * pages (Cash\CashTransferController, Dashboard\CashSessionController)
 * rather than being duplicated here.
 */
class CashOperationsController extends Controller
{
    public function __construct(private CashTransferService $transfers)
    {
        // The 'permission:' route middleware alias (CheckPermission) checks
        // a single literal permission name with no OR syntax, so "any of"
        // needs this closure form — same pattern BellScheduleController
        // already uses for its own two-permission gate.
        $this->middleware(function (Request $request, $next) {
            abort_unless($request->user()?->hasAnyPermission(['manage cash', 'transfer cash', 'view cash reports']), 403);

            return $next($request);
        })->only(['index']);

        $this->middleware(function (Request $request, $next) {
            abort_unless($request->user()?->hasAnyPermission(['manage cash', 'transfer cash']), 403);

            return $next($request);
        })->only(['handoverForm', 'handover', 'ownerReturnForm', 'ownerReturn']);
    }

    public function index(): View
    {
        $groups = [
            'operating' => ['label' => 'Операционная касса', 'type' => CashAccount::TYPE_CASH],
            'owner' => ['label' => 'Касса владельца', 'type' => CashAccount::TYPE_OWNER_CASH],
            'bank' => ['label' => 'Банковский счёт', 'type' => CashAccount::TYPE_BANK],
            'instapay' => ['label' => 'InstaPay', 'type' => CashAccount::TYPE_INSTAPAY],
        ];

        $today = today();
        foreach ($groups as $key => &$group) {
            $group['accounts'] = CashAccount::query()
                ->where('type', $group['type'])
                ->orderBy('name')
                ->get()
                ->map(function (CashAccount $account) use ($today) {
                    return [
                        'account' => $account,
                        'today_in' => (string) CashTransaction::query()
                            ->where('cash_account_id', $account->id)
                            ->where('type', CashTransaction::TYPE_IN)
                            ->whereDate('created_at', $today)
                            ->sum('amount'),
                        'today_out' => (string) CashTransaction::query()
                            ->where('cash_account_id', $account->id)
                            ->where('type', CashTransaction::TYPE_OUT)
                            ->whereDate('created_at', $today)
                            ->sum('amount'),
                    ];
                });
        }
        unset($group);

        $recentTransfers = CashTransfer::query()
            ->with(['fromAccount', 'toAccount', 'creator'])
            ->latest('transfer_date')
            ->latest('id')
            ->limit(10)
            ->get();

        return view('dashboard.cash.operations.index', [
            'groups' => $groups,
            'recentTransfers' => $recentTransfers,
        ]);
    }

    public function handoverForm(): View
    {
        return view('dashboard.cash.operations.handover', [
            'operatingAccounts' => $this->accountsOfType(CashAccount::TYPE_CASH),
            'ownerAccounts' => $this->accountsOfType(CashAccount::TYPE_OWNER_CASH),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function handover(Request $request): RedirectResponse
    {
        $data = $this->validateTransferRequest($request);

        $this->transfers->transfer(
            fromAccountId: $data['from_account_id'],
            toAccountId: $data['to_account_id'],
            amount: $data['amount'],
            purpose: 'Передача дневной выручки владельцу',
            notes: $data['notes'],
            actor: $request->user(),
            transferType: CashTransfer::TYPE_HANDOVER,
            idempotencyKey: $data['idempotency_key'],
        );

        return redirect()
            ->route('dashboard.cash.operations.index')
            ->with('success', 'Выручка передана владельцу.');
    }

    public function ownerReturnForm(): View
    {
        return view('dashboard.cash.operations.owner-return', [
            'ownerAccounts' => $this->accountsOfType(CashAccount::TYPE_OWNER_CASH),
            'operatingAccounts' => $this->accountsOfType(CashAccount::TYPE_CASH),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function ownerReturn(Request $request): RedirectResponse
    {
        $data = $this->validateTransferRequest($request);

        $this->transfers->transfer(
            fromAccountId: $data['from_account_id'],
            toAccountId: $data['to_account_id'],
            amount: $data['amount'],
            purpose: 'Пополнение операционной кассы',
            notes: $data['notes'],
            actor: $request->user(),
            transferType: CashTransfer::TYPE_OWNER_RETURN,
            idempotencyKey: $data['idempotency_key'],
        );

        return redirect()
            ->route('dashboard.cash.operations.index')
            ->with('success', 'Операционная касса пополнена.');
    }

    /** @return array{from_account_id:int, to_account_id:int, amount:string, notes:?string, idempotency_key:?string} */
    private function validateTransferRequest(Request $request): array
    {
        $data = $request->validate([
            'from_account_id' => ['required', 'exists:cash_accounts,id', 'different:to_account_id'],
            'to_account_id' => ['required', 'exists:cash_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        return [
            'from_account_id' => (int) $data['from_account_id'],
            'to_account_id' => (int) $data['to_account_id'],
            'amount' => (string) $data['amount'],
            'notes' => $data['notes'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ];
    }

    private function accountsOfType(string $type)
    {
        return CashAccount::query()->where('type', $type)->where('is_active', true)->orderBy('name')->get();
    }
}

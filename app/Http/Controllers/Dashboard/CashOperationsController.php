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
 * for the four canonical accounts (operating / owner / bank / instapay)
 * plus the two specialised, pre-filled transfer workflows the real
 * business process needs (daily handover to the owner, owner topping the
 * operating drawer back up).
 *
 * Canonical accounts are resolved by CashAccount's role (operating/owner/
 * bank/instapay) — never by display name or "all accounts of this type"
 * — see the migration that introduced the role column for why a
 * name-based lookup is unsafe. Ordinary, non-canonical cash-type drawers
 * (Phase 3 already supports several) are untouched by this controller;
 * Dashboard\CashSessionController's own type-based listing there is
 * intentionally left as-is.
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
        $roles = [
            'operating' => ['label' => 'Операционная касса', 'account' => CashAccount::operating()],
            'owner' => ['label' => 'Касса владельца', 'account' => CashAccount::owner()],
            'bank' => ['label' => 'Банковский счёт', 'account' => CashAccount::bank()],
            'instapay' => ['label' => 'InstaPay', 'account' => CashAccount::instapay()],
        ];

        $today = today();
        foreach ($roles as &$role) {
            $account = $role['account'];
            $role['today_in'] = $account ? (string) CashTransaction::query()
                ->where('cash_account_id', $account->id)
                ->where('type', CashTransaction::TYPE_IN)
                ->whereDate('created_at', $today)
                ->sum('amount') : '0.00';
            $role['today_out'] = $account ? (string) CashTransaction::query()
                ->where('cash_account_id', $account->id)
                ->where('type', CashTransaction::TYPE_OUT)
                ->whereDate('created_at', $today)
                ->sum('amount') : '0.00';
        }
        unset($role);

        $recentTransfers = CashTransfer::query()
            ->with(['fromAccount', 'toAccount', 'creator'])
            ->latest('transfer_date')
            ->latest('id')
            ->limit(10)
            ->get();

        return view('dashboard.cash.operations.index', [
            'roles' => $roles,
            'recentTransfers' => $recentTransfers,
        ]);
    }

    public function handoverForm(): View|RedirectResponse
    {
        $operating = CashAccount::operating();
        $owner = CashAccount::owner();
        if (! $operating || ! $owner) {
            return $this->missingCanonicalAccount();
        }

        return view('dashboard.cash.operations.handover', [
            'operating' => $operating,
            'owner' => $owner,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function handover(Request $request): RedirectResponse
    {
        $operating = CashAccount::operating();
        $owner = CashAccount::owner();
        abort_unless($operating && $owner, 422, 'Канонические счета ещё не настроены.');

        $data = $this->validateTransferRequest($request, $operating, $owner);

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

    public function ownerReturnForm(): View|RedirectResponse
    {
        $operating = CashAccount::operating();
        $owner = CashAccount::owner();
        if (! $operating || ! $owner) {
            return $this->missingCanonicalAccount();
        }

        return view('dashboard.cash.operations.owner-return', [
            'operating' => $operating,
            'owner' => $owner,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function ownerReturn(Request $request): RedirectResponse
    {
        $operating = CashAccount::operating();
        $owner = CashAccount::owner();
        abort_unless($operating && $owner, 422, 'Канонические счета ещё не настроены.');

        // Reversed direction from handover: owner -> operating.
        $data = $this->validateTransferRequest($request, $owner, $operating);

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

    /**
     * Validates the submitted amount/notes/idempotency-key normally, but
     * the from/to accounts are never taken from user input — they are
     * always the two canonical accounts this specific workflow moves
     * money between, exactly matching what the hidden fields on the form
     * are meant to represent. This closes off any possibility of a
     * tampered or stale request pointing the transfer at the wrong pair
     * of accounts.
     *
     * @return array{from_account_id:int, to_account_id:int, amount:string, notes:?string, idempotency_key:?string}
     */
    private function validateTransferRequest(Request $request, CashAccount $from, CashAccount $to): array
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        return [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => (string) $data['amount'],
            'notes' => $data['notes'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ];
    }

    private function missingCanonicalAccount(): RedirectResponse
    {
        return redirect()
            ->route('dashboard.cash.operations.index')
            ->with('error', 'Канонические счета ещё не настроены. Обратитесь к администратору.');
    }
}

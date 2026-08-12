<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\CloseCashSessionRequest;
use App\Http\Requests\OpenCashSessionRequest;
use App\Models\CashAccount;
use App\Models\CashSession;
use App\Services\Finance\CashSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Phase 3 — cash-drawer session (кассовая смена) lifecycle UI.
 *
 * Thin controller: all invariants (single open session per drawer, opening
 * baseline provenance, reconciliation math, variance authorisation) live in
 * CashSessionService. Closed sessions are read-only end to end.
 */
class CashSessionController extends Controller
{
    public function __construct(private CashSessionService $sessions)
    {
        $this->middleware('permission:view cash sessions')->only(['index', 'show']);
        $this->middleware('permission:open cash sessions')->only(['create', 'store']);
        $this->middleware('permission:close cash sessions')->only(['close']);
    }

    public function index(): View
    {
        $drawers = CashAccount::query()
            ->where('type', CashAccount::TYPE_CASH)
            ->orderBy('name')
            ->get()
            ->map(fn (CashAccount $account) => [
                'account' => $account,
                'active' => $this->sessions->activeFor($account),
            ]);

        $history = CashSession::query()
            ->with(['account', 'opener', 'closer'])
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('dashboard.cash.sessions.index', compact('drawers', 'history'));
    }

    public function create(): View
    {
        $accounts = CashAccount::query()
            ->where('type', CashAccount::TYPE_CASH)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (CashAccount $account) => [
                'account' => $account,
                'active' => $this->sessions->activeFor($account),
                'opening' => $this->sessions->resolveOpeningExpected($account),
            ]);

        return view('dashboard.cash.sessions.create', compact('accounts'));
    }

    public function store(OpenCashSessionRequest $request): RedirectResponse
    {
        $account = CashAccount::findOrFail($request->integer('cash_account_id'));

        $session = $this->sessions->open($account, $request->user(), $request->input('open_note'));

        return redirect()
            ->route('dashboard.cash.sessions.show', $session)
            ->with('success', 'Смена открыта.');
    }

    public function show(CashSession $cashSession): View
    {
        $cashSession->load([
            'account', 'opener', 'closer',
            'transactions' => fn ($query) => $query->latest('id')->with('invoicePayment'),
        ]);

        return view('dashboard.cash.sessions.show', [
            'session' => $cashSession,
            'cashIn' => $cashSession->cashIn(),
            'cashOut' => $cashSession->cashOut(),
            'expected' => $cashSession->expectedClosing(),
            'canCloseWithVariance' => (bool) request()->user()?->can('close cash sessions with variance'),
            'canClose' => (bool) request()->user()?->can('close cash sessions'),
        ]);
    }

    public function close(CloseCashSessionRequest $request, CashSession $cashSession): RedirectResponse
    {
        $this->sessions->close(
            $cashSession,
            $request->user(),
            (string) $request->input('closing_counted'),
            $request->input('close_note'),
        );

        return redirect()
            ->route('dashboard.cash.sessions.show', $cashSession)
            ->with('success', 'Смена закрыта.');
    }
}

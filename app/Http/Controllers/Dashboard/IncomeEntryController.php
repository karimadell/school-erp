<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Finance Workspace UX corrective — the single "Приход" entry point an
 * operational user reaches from the + Приход button. It never records
 * money itself: every option here routes into an already-canonical flow
 * (quick registration, the student search/invoice/payment workspace, or —
 * since the Non-Tuition Revenues V1 integration — the dashboard-native
 * RevenueEntryController for genuinely non-student income).
 */
class IncomeEntryController extends Controller
{
    public function __construct()
    {
        // Same gate as the Финансы landing page (FinanceOperationsController::
        // workspace) — Приход is the entry point into that same
        // money-collection domain, not a separately-permissioned surface.
        // The Revenue create/store actions themselves carry their own,
        // narrower 'manage revenues' authorization independently.
        $this->middleware('permission:view invoices');
    }

    public function index(): View
    {
        return view('dashboard.finance.income.index');
    }

    // Пожертвование — routes into the canonical Non-Tuition Revenue flow
    // with the 'donation' category pre-selected and locked, never a
    // parallel implementation (RevenueEntryController::create() resolves
    // the actual RevenueCategory row from this query param).
    public function donation(): RedirectResponse
    {
        return redirect()->route('dashboard.finance.income.revenue.create', ['type' => 'donation']);
    }

    // Прочий приход — same canonical Revenue flow, but the user picks
    // their own category (cafeteria/fine/other) instead of it being locked.
    public function other(): RedirectResponse
    {
        return redirect()->route('dashboard.finance.income.revenue.create');
    }
}

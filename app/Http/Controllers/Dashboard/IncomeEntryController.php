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

    // Буфет — same canonical Revenue flow, category pre-selected and
    // locked to the dedicated 'buffet' category (owner-approved Finance
    // category separation, pre-go-live). One daily handover amount per
    // entry — no product-level POS/inventory. Never the legacy
    // 'cafeteria' category, never mixed with School Food.
    public function buffet(): RedirectResponse
    {
        return redirect()->route('dashboard.finance.income.revenue.create', ['type' => 'buffet']);
    }

    // Прочий приход — same canonical Revenue flow, but the user picks
    // their own category (cafeteria/fine/other) instead of it being locked.
    public function other(): RedirectResponse
    {
        return redirect()->route('dashboard.finance.income.revenue.create');
    }

    // Столовая (Student, Phase 1) — daily meal charges are Student Food
    // accounting (Invoice/Payment/CashTransaction via ChargeAndCollectService),
    // never RevenueEntry, so this does NOT redirect into the Revenue flow
    // like buffet()/donation()/other() above. It reuses the exact same
    // student search screen the "Оплата ученика"/"Услуга" cards already
    // use — no new search UI — whose rows carry a "Столовая" action into
    // StolovayaController::create() once a student is chosen.
    public function stolovaya(): RedirectResponse
    {
        return redirect()->route('dashboard.finance.income.students');
    }
}

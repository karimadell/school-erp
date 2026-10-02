<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    // Прочий приход — same canonical Revenue flow, but the user picks their
    // own residual category (fine/other, …) instead of it being locked; the
    // controlled and legacy categories are excluded there (RevenueEntryController).
    public function other(): RedirectResponse
    {
        return redirect()->route('dashboard.finance.income.revenue.create');
    }

    // Столовая — one Приход card for two wholly separate engines: Student
    // meals are Student Food accounting (Invoice/Payment/CashTransaction via
    // the student search → StolovayaController), Employee meals are
    // StaffFoodPurchase/RevenueEntry(school_food) via EmployeeStolovayaController.
    // This only chooses where to navigate, per the permission each
    // destination itself enforces ('manage invoices' / 'manage employee
    // stolovaya'): a chooser when both apply, straight to the only usable
    // side otherwise, 403 when neither does.
    public function stolovaya(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $canStudent = $user->can('manage invoices');
        $canEmployee = $user->can('manage employee stolovaya');

        abort_unless($canStudent || $canEmployee, 403);

        if ($canStudent && $canEmployee) {
            return view('dashboard.finance.income.stolovaya');
        }

        return $canStudent
            ? redirect()->route('dashboard.finance.income.students')
            : redirect()->route('dashboard.employee-stolovaya.create');
    }
}

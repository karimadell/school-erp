<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Finance Workspace UX corrective — the single "Приход" entry point an
 * operational user reaches from the + Приход button. It never records
 * money itself: every option here routes into an already-canonical flow
 * (quick registration, the student search/invoice/payment workspace) or,
 * for the two non-tuition kinds that have no integrated backend yet on
 * this branch, a clearly-marked non-destructive placeholder.
 *
 * Non-Tuition Revenue (RevenueService/RevenueEntry/RevenueCategory) exists
 * on a separate, unmerged worktree (feature/finance-nontuition-revenues-v1)
 * — this controller deliberately does not reimplement any part of it. Once
 * that module is merged, donation()/other() become simple redirects into
 * its dashboard-native create routes instead of rendering the placeholder
 * view.
 */
class IncomeEntryController extends Controller
{
    public function __construct()
    {
        // Same gate as the Финансы landing page (FinanceOperationsController::
        // workspace) — Приход is the entry point into that same
        // money-collection domain, not a separately-permissioned surface.
        $this->middleware('permission:view invoices');
    }

    public function index(): View
    {
        return view('dashboard.finance.income.index');
    }

    public function donation(): View
    {
        return view('dashboard.finance.income.placeholder', [
            'title' => __('finance_workspace.income_type_donation'),
        ]);
    }

    public function other(): View
    {
        return view('dashboard.finance.income.placeholder', [
            'title' => __('finance_workspace.income_type_other'),
        ]);
    }
}

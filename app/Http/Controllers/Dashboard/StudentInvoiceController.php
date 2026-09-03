<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Fee;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Services\Finance\InvoiceIssuanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StudentInvoiceController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage invoices');
    }

    public function create(Student $student): View
    {
        $student->load(['currentEnrollment.academicYear','currentEnrollment.grade','currentEnrollment.serviceSubscriptions.fee']);
        $year = $student->currentEnrollment?->academicYear;
        $fees = Fee::active()->where('category', '!=', Fee::CATEGORY_FOOD)->with(['prices' => fn ($query) => $query
            ->when($year, fn ($query) => $query->where('academic_year_id', $year->id))
            ->where('is_active', true)->orderByDesc('start_date')])->orderBy('category')->orderBy('name_ru')->get();

        $paymentPlans = PaymentPlan::active()->with('installments')->orderBy('sort_order')->get();
        // Finance V2, Phase 2B corrective pass (review finding M2): a
        // PaymentPlan is only ever valid for a Fee it's explicitly assigned
        // to (Phase 2B's own rule) — the dropdown must not show every
        // active plan regardless of which service(s) are selected, the
        // same UX symptom Phase 2B exists to fix elsewhere. This map lets
        // the page's existing fee-selection JS narrow the select to the
        // intersection of plans assigned to every currently-checked Fee
        // (matching InvoiceIssuanceService::issue()'s own "every Fee must
        // have the plan assigned" server-side rule). Server-side validation
        // in issue() remains the authoritative backstop regardless.
        $feePlanMap = Fee::with('assignedPaymentPlans:id')->get(['id'])
            ->mapWithKeys(fn (Fee $fee) => [$fee->id => $fee->assignedPaymentPlans->pluck('id')->all()]);

        return view('dashboard.finance.invoices.create', compact('student', 'year', 'fees', 'paymentPlans', 'feePlanMap'));
    }

    public function store(StoreInvoiceRequest $request, Student $student, InvoiceIssuanceService $issuer): RedirectResponse
    {
        $invoice = $issuer->issue($student, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return redirect()->route('dashboard.invoices.show', $invoice)->with('success', 'Счёт успешно создан.');
    }
}

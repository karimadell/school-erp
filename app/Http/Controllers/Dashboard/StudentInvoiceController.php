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
        $fees = Fee::active()->with(['prices' => fn ($query) => $query
            ->when($year, fn ($query) => $query->where('academic_year_id', $year->id))
            ->where('is_active', true)->orderByDesc('start_date')])->orderBy('category')->orderBy('name_ru')->get();

        $paymentPlans = PaymentPlan::active()->with('installments')->orderBy('sort_order')->get();
        return view('dashboard.finance.invoices.create', compact('student', 'year', 'fees', 'paymentPlans'));
    }

    public function store(StoreInvoiceRequest $request, Student $student, InvoiceIssuanceService $issuer): RedirectResponse
    {
        $invoice = $issuer->issue($student, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return redirect()->route('dashboard.invoices.show', $invoice)->with('success', 'Счёт успешно создан.');
    }
}

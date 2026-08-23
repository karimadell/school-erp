<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\InvoicePayment;
use App\Models\PromiseToPay;
use App\Models\Student;
use App\Services\Finance\PromiseToPayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PromiseToPayController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage invoices');
    }

    public function store(Request $request, Student $student, PromiseToPayService $service): RedirectResponse
    {
        $data = $request->validate([
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'promised_amount' => ['required', 'numeric', 'min:0.01'],
            'expected_payment_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $service->create($student, $data, $request->user());

        return redirect()->route('dashboard.students.finance', $student)->with('success', 'Обещание оплаты сохранено без изменения баланса.');
    }

    public function cancel(Request $request, PromiseToPay $promiseToPay, PromiseToPayService $service): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $service->cancel($promiseToPay, $request->user(), $data['note'] ?? null);

        return redirect()->route('dashboard.students.finance', $promiseToPay->student_id)->with('success', 'Обещание оплаты отменено.');
    }

    public function fulfill(Request $request, PromiseToPay $promiseToPay, PromiseToPayService $service): RedirectResponse
    {
        $data = $request->validate(['invoice_payment_id' => ['required', 'integer', 'exists:invoice_payments,id']]);
        $service->fulfill($promiseToPay, InvoicePayment::findOrFail($data['invoice_payment_id']), $request->user());

        return redirect()->route('dashboard.students.finance', $promiseToPay->student_id)->with('success', 'Обещание связано с фактическим платежом.');
    }
}

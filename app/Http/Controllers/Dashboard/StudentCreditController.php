<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\StudentCredit;
use App\Services\Finance\StudentCreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StudentCreditController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage invoices');
    }

    public function apply(Request $request, StudentCredit $studentCredit, StudentCreditService $service): RedirectResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $service->apply($studentCredit, Invoice::findOrFail($data['invoice_id']), (string) $data['amount'], $data['idempotency_key'], $request->user());

        return redirect()->route('dashboard.students.finance', $studentCredit->student_id)->with('success', 'Кредит применён к счёту.');
    }
}

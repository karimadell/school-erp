<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Services\Finance\ServiceCoverageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ServiceCoverageController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage invoices');
    }

    public function store(Request $request, Student $student, ServiceCoverageService $service): RedirectResponse
    {
        $data = $request->validate([
            'invoice_item_id' => ['required', 'integer', 'exists:invoice_items,id'],
            'fee_price_id' => ['required', 'integer', 'exists:fee_prices,id'],
            'coverage_start' => ['required', 'date'],
            'coverage_end' => ['required', 'date'],
            'billing_unit' => ['required', 'in:monthly,daily'],
        ]);
        $item = InvoiceItem::with('invoice')->findOrFail($data['invoice_item_id']);
        abort_unless($item->invoice->student_id === $student->id, 404);
        $service->record($item, $data, $request->user(), $student);

        return redirect()->route('dashboard.students.finance', $student)->with('success', 'Покрытие услуги сохранено.');
    }
}

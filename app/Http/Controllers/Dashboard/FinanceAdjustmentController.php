<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\FeePrice;
use App\Models\ServiceCoverage;
use App\Services\Finance\TariffAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceAdjustmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage invoices')->only('preview');
        $this->middleware('permission:approve tariff adjustments')->only('store');
    }

    public function preview(Request $request, TariffAdjustmentService $service): View
    {
        $data = $request->validate([
            'service_coverage_id' => ['nullable', 'integer', 'exists:service_coverages,id'],
            'new_fee_price_id' => ['required', 'integer', 'exists:fee_prices,id'],
        ]);
        $price = FeePrice::findOrFail($data['new_fee_price_id']);
        $previews = isset($data['service_coverage_id'])
            ? collect([$service->preview(ServiceCoverage::with(['student', 'fee', 'feePrice', 'invoiceItem.invoice'])->findOrFail($data['service_coverage_id']), $price)])
            : $service->previewAffected($price);

        return view('dashboard.finance.adjustments.preview', [
            'previews' => $previews,
            'newPrice' => $price,
        ]);
    }

    public function store(Request $request, TariffAdjustmentService $service): RedirectResponse
    {
        $data = $request->validate([
            'service_coverage_id' => ['required', 'integer', 'exists:service_coverages,id'],
            'new_fee_price_id' => ['required', 'integer', 'exists:fee_prices,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $coverage = ServiceCoverage::findOrFail($data['service_coverage_id']);
        $adjustment = $service->approve($coverage, FeePrice::findOrFail($data['new_fee_price_id']), $request->user(), $data['note'] ?? null);

        return redirect()->route('dashboard.students.finance', $coverage->student_id)
            ->with('success', $adjustment ? 'Корректировка тарифа проведена.' : 'Для выбранного покрытия разница отсутствует.');
    }
}

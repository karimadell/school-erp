<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolloverFinanceTariffsRequest;
use App\Models\AcademicYear;
use App\Services\Finance\AcademicYearTariffRolloverService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FinanceTariffRolloverController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage fee prices');
    }

    public function create(): View
    {
        return view('dashboard.finance.tariffs.rollover', [
            'years' => AcademicYear::query()->orderByDesc('start_date')->get(),
        ]);
    }

    public function preview(RolloverFinanceTariffsRequest $request, AcademicYearTariffRolloverService $service): View
    {
        return view('dashboard.finance.tariffs.rollover-preview', [
            'preview' => $service->preview($request->integer('source_academic_year_id'), $request->integer('target_academic_year_id')),
        ]);
    }

    public function store(RolloverFinanceTariffsRequest $request, AcademicYearTariffRolloverService $service): RedirectResponse
    {
        $result = $service->copy($request->integer('source_academic_year_id'), $request->integer('target_academic_year_id'));

        return redirect()->route('dashboard.finance.tariffs.index', ['academic_year_id' => $request->integer('target_academic_year_id')])
            ->with('success', "Копирование завершено. Создано тарифов: {$result['created']}. Пропущено: {$result['skipped']}.");
    }
}

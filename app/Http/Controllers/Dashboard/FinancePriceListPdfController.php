<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportFinancePriceListRequest;
use App\Models\AcademicYear;
use App\Services\Finance\AcademicYearPriceListService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FinancePriceListPdfController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage fee prices');
    }

    public function create(Request $request): View
    {
        $years = AcademicYear::query()->orderByDesc('start_date')->get();
        $selectedYearId = $request->integer('academic_year_id')
            ?: (int) ($years->firstWhere('is_active', true)?->id ?? $years->first()?->id);

        return view('dashboard.finance.tariffs.price-list-export', [
            'years' => $years,
            'selectedYearId' => $selectedYearId,
            'categoryOptions' => AcademicYearPriceListService::categoryOptions(),
        ]);
    }

    public function download(ExportFinancePriceListRequest $request, AcademicYearPriceListService $service): Response
    {
        $year = AcademicYear::findOrFail($request->integer('academic_year_id'));
        $categories = $request->input('categories', ExportFinancePriceListRequest::categories());
        $data = $service->data($year, $categories, $request->boolean('include_inactive'));
        $data['generatedAt'] = now();
        $data['logoPath'] = public_path('images/school-logo.png');

        $safeYear = str_replace(['/', '\\'], '-', $year->name);

        return Pdf::loadView('dashboard.finance.tariffs.price-list-pdf', $data)
            ->setPaper('a4')
            ->download("Прайс-{$safeYear}.pdf");
    }
}

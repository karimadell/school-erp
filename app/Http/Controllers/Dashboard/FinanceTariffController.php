<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinanceTariffRequest;
use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\MealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceTariffController extends Controller
{
    public function __construct() { $this->middleware('permission:manage fee prices'); }

    public function index(Request $request): View
    {
        $query = FeePrice::with(['fee', 'academicYear'])->orderByDesc('start_date')->orderByDesc('id');
        $query->when($request->integer('fee_id'), fn ($q, $id) => $q->where('fee_id', $id));
        $query->when($request->integer('academic_year_id'), fn ($q, $id) => $q->where('academic_year_id', $id));
        $query->when($request->date('effective_date'), fn ($q, $date) => $q->whereDate('start_date', '<=', $date)->where(fn ($x) => $x->whereNull('end_date')->orWhereDate('end_date', '>=', $date)));
        if ($request->filled('status')) {
            match ($request->string('status')->toString()) {
                'current' => $query->where('is_active', true)->current(),
                'future' => $query->where('is_active', true)->whereDate('start_date', '>', today()),
                'expired' => $query->whereNotNull('end_date')->whereDate('end_date', '<', today()),
                'inactive' => $query->where('is_active', false), default => null,
            };
        }
        return view('dashboard.finance.tariffs.index', ['tariffs' => $query->paginate(25)->withQueryString(), 'services' => Fee::orderBy('name_ru')->get(), 'years' => AcademicYear::orderByDesc('start_date')->get()]);
    }

    public function create(Request $request): View
    {
        return view('dashboard.finance.tariffs.create', ['services' => Fee::active()->orderBy('name_ru')->get(), 'years' => AcademicYear::orderByDesc('start_date')->get(), 'grades' => Grade::ordered()->get(), 'mealPlans' => MealPlan::active()->orderBy('name_ru')->get(), 'selectedFeeId' => $request->integer('fee_id') ?: null]);
    }

    public function store(StoreFinanceTariffRequest $request): RedirectResponse
    {
        $tariff = FeePrice::create($request->validated());
        return redirect()->route('dashboard.finance.tariffs.show', $tariff)->with('success', 'Новая версия тарифа создана. Старые счета не изменены.');
    }

    public function show(FeePrice $feePrice): View
    {
        $feePrice->load(['fee', 'academicYear', 'grade']);
        return view('dashboard.finance.tariffs.show', ['tariff' => $feePrice]);
    }
}

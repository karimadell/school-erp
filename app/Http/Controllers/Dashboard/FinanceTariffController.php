<?php

namespace App\Http\Controllers\Dashboard;

use App\Filament\Resources\FeePrices\FeePriceResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinanceTariffRequest;
use App\Models\AcademicYear;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\MealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceTariffController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage fee prices');
    }

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

    /**
     * Trusted, server-side-validated query-string DEFAULTS only — never
     * authoritative, never bypassing the form's own normal validation on
     * submit (StoreFinanceTariffRequest). Every value is re-checked
     * against real master data before being reflected as a default; an
     * unrecognized value is simply dropped, matching the exact same
     * contract Filament's CreateFeePrice::fillForm() already enforces for
     * the same fields — used by MissingTariffGuidanceService's Add Price
     * link. amount is deliberately never accepted here.
     */
    public function create(Request $request): View
    {
        $academicYearId = $request->integer('academic_year_id') ?: null;
        if ($academicYearId && ! AcademicYear::whereKey($academicYearId)->exists()) {
            $academicYearId = null;
        }
        $gradeId = $request->integer('grade_id') ?: null;
        if ($gradeId && ! Grade::whereKey($gradeId)->exists()) {
            $gradeId = null;
        }
        $gradeGroup = in_array($request->query('grade_group'), FeePrice::GRADE_GROUPS, true)
            ? $request->query('grade_group') : null;
        $paymentPeriod = array_key_exists((string) $request->query('payment_period'), FeePriceResource::paymentPeriodLabels())
            ? $request->query('payment_period') : null;
        $enrollmentModeCode = EnrollmentMode::query()->where('code', $request->query('enrollment_mode'))->value('code');

        return view('dashboard.finance.tariffs.create', [
            'services' => Fee::active()->orderBy('name_ru')->get(),
            'years' => AcademicYear::orderByDesc('start_date')->get(),
            'grades' => Grade::ordered()->get(),
            'mealPlans' => MealPlan::active()->orderBy('name_ru')->get(),
            'enrollmentModes' => EnrollmentMode::query()->orderBy('display_order')->pluck('name_ru', 'code'),
            'selectedFeeId' => $request->integer('fee_id') ?: null,
            'selectedAcademicYearId' => $academicYearId,
            'selectedGradeId' => $gradeId,
            'selectedGradeGroup' => $gradeGroup,
            'selectedPaymentPeriod' => $paymentPeriod,
            'selectedEnrollmentModeCode' => $enrollmentModeCode,
        ]);
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

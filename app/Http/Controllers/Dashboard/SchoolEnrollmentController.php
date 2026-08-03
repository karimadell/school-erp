<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSchoolEnrollmentRequest;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\EnrollmentMode;
use App\Models\Stage;
use App\Services\Admissions\SchoolEnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SchoolEnrollmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage students');
    }

    public function index(): View
    {
        return view('dashboard.school-enrollment.index', [
            'enrollments' => Enrollment::query()
                ->with(['student', 'academicYear', 'stage', 'grade', 'schoolClass', 'enrollmentMode'])
                ->latest()->paginate(15),
        ]);
    }

    public function create(): View
    {
        $years = AcademicYear::query()->where('is_active', true)->orderByDesc('start_date')->get();
        $selectedYear = $years->first();
        $prices = collect();
        if ($selectedYear) {
            $pricingDate = now()->betweenIncluded($selectedYear->start_date, $selectedYear->end_date)
                ? now()->toDateString()
                : $selectedYear->start_date->toDateString();
            $prices = FeePrice::query()->with('fee')
                ->where('academic_year_id', $selectedYear->id)
                ->where('currency', 'EGP')->where('is_active', true)
                ->whereDate('start_date', '<=', $pricingDate)
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $pricingDate))
                ->whereHas('fee', fn ($query) => $query->where('is_active', true))
                ->orderBy('fee_id')->orderBy('amount')->get();
        }

        $stages = Stage::query()->where('is_active', true)->with([
            'grades' => fn ($query) => $query->orderBy('level')->orderBy('id'),
            'grades.classes' => fn ($query) => $query->where('is_active', true)->orderBy('code'),
        ])->orderBy('order')->get();

        return view('dashboard.school-enrollment.create', [
            'academicYears' => $years,
            'enrollmentModes' => EnrollmentMode::active()->ordered()->get(),
            'stages' => $stages,
            'structureData' => $stages->mapWithKeys(fn ($stage) => [$stage->id => $stage->grades->map(fn ($grade) => [
                'id' => $grade->id,
                'name' => $grade->name,
                'classes' => $grade->classes->map(fn ($class) => [
                    'id' => $class->id,
                    'name' => $class->name_ru ?: $class->code,
                ])->values(),
            ])->values()]),
            'pricesByCategory' => $prices->groupBy(fn (FeePrice $price) => $this->serviceGroup($price->fee)),
        ]);
    }

    public function store(
        StoreSchoolEnrollmentRequest $request,
        SchoolEnrollmentService $service,
    ): RedirectResponse {
        $result = $service->enroll($request->validated(), $request->user(), $request->file('photo'));

        return redirect()->route('dashboard.invoices.show', $result['invoice'])
            ->with('success', 'Ученик успешно зачислен. Черновик счёта создан без оплаты.');
    }

    private function serviceGroup(Fee $fee): string
    {
        return match ($fee->category) {
            Fee::CATEGORY_TUITION, Fee::CATEGORY_TUITION_REGULAR,
            Fee::CATEGORY_TUITION_FAMILY, Fee::CATEGORY_TUITION_EXTERNAL => 'Обучение',
            Fee::CATEGORY_REGISTRATION => 'Регистрационный взнос',
            Fee::CATEGORY_TRANSPORT => 'Транспорт',
            Fee::CATEGORY_FOOD => 'Питание',
            Fee::CATEGORY_UNIFORM => 'Школьная форма',
            default => 'Дополнительные занятия',
        };
    }
}

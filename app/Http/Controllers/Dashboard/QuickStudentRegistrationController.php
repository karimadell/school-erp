<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuickStudentRegistrationRequest;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\MealPlan;
use App\Models\Stage;
use App\Services\Admissions\QuickStudentRegistrationService;
use App\Services\Finance\InvoiceCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class QuickStudentRegistrationController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage invoices');
    }

    public function create(): View
    {
        $academicYears = AcademicYear::where('is_active', true)->orderByDesc('start_date')->get();
        $modes = EnrollmentMode::where('is_active', true)->orderBy('name_ru')->orderBy('id')->get();
        $fees = Fee::with(['prices' => fn ($query) => $query->active()->current()->orderByDesc('start_date')])
            ->active()->orderBy('category')->orderBy('name_ru')->get();

        return view('dashboard.quick-registration.create', [
            'academicYears' => $academicYears,
            'defaultAcademicYearId' => $academicYears->count() === 1 ? $academicYears->first()->id : null,
            'stages' => Stage::with([
                'grades' => fn ($query) => $query->orderBy('level')->orderBy('id'),
                'grades.classes' => fn ($query) => $query->where('is_active', true)->orderBy('code'),
            ])
                ->where('is_active', true)->orderBy('order')->get(),
            'modes' => $modes,
            'defaultEnrollmentModeId' => $modes->count() === 1 ? $modes->first()->id : null,
            'fees' => $fees,
            'mealPlans' => MealPlan::active()->orderBy('name_ru')->get(),
            'cashAccounts' => CashAccount::where('is_active', true)->orderBy('name')->get(),
            'transportRoutes' => DB::table('transport_routes')->orderBy('name')->get(),
            'uniformProducts' => DB::table('uniform_products')->where('is_active', true)->orderBy('name_ru')->orderBy('size')->get(),
        ]);
    }

    public function store(
        StoreQuickStudentRegistrationRequest $request,
        QuickStudentRegistrationService $service,
    ): RedirectResponse {
        $result = $service->register($request->validated(), $request->user());

        return redirect()->route('dashboard.quick-registration.summary', $result['invoice'])
            ->with('success', 'Ученик предварительно зарегистрирован, счёт создан в EGP.');
    }

    public function price(Request $request, InvoiceCalculationService $calculator): JsonResponse
    {
        $data = $request->validate([
            'fee_id' => ['required', 'integer', 'exists:fees,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'item' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:50'],
            'transport_area' => ['nullable', 'string', 'max:150'],
            'grade_id' => ['nullable', 'integer', 'exists:grades,id'],
            'grade_group' => ['nullable', 'string', 'max:100'],
            'payment_period' => ['nullable', 'string', 'max:50'],
            'first_last_month' => ['nullable', 'boolean'],
            'meal_plan_id' => ['nullable', 'integer', 'exists:meal_plans,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ]);
        $fee = Fee::findOrFail($data['fee_id']);
        $tuitionCategories = [
            Fee::CATEGORY_TUITION, Fee::CATEGORY_TUITION_REGULAR,
            Fee::CATEGORY_TUITION_FAMILY, Fee::CATEGORY_TUITION_EXTERNAL,
        ];
        $calculation = $calculator->calculate(items: [[
            'fee_id' => $fee->id,
            'quantity' => $data['quantity'],
            'grade_id' => in_array($fee->category, $tuitionCategories, true) && blank($data['grade_group'] ?? null)
                ? ($data['grade_id'] ?? null)
                : null,
            'grade_group' => $data['grade_group'] ?? null,
            'payment_period' => $data['payment_period'] ?? null,
            'first_last_month' => (bool) ($data['first_last_month'] ?? false),
            'item' => $fee->category === Fee::CATEGORY_UNIFORM ? ($data['item'] ?? null) : null,
            'size' => $fee->category === Fee::CATEGORY_UNIFORM ? ($data['size'] ?? null) : null,
            'option_type' => match ($fee->category) {
                Fee::CATEGORY_TRANSPORT => 'zone',
                Fee::CATEGORY_FOOD => 'meal_plan',
                default => null,
            },
            'option_value' => match ($fee->category) {
                Fee::CATEGORY_TRANSPORT => $data['transport_area'] ?? null,
                Fee::CATEGORY_FOOD => isset($data['meal_plan_id']) ? (string) $data['meal_plan_id'] : null,
                default => null,
            },
        ]], academicYearId: (int) $data['academic_year_id']);

        return response()->json([
            'unit_price' => $calculation['line_items'][0]['unit_price'],
            'amount' => $calculation['line_items'][0]['amount'],
            'currency' => InvoiceCalculationService::CURRENCY,
        ]);
    }

    public function summary(Invoice $invoice): View
    {
        $invoice->load([
            'student.enrollments.stage', 'student.enrollments.schoolClass',
            'student.enrollments.enrollmentMode', 'academicYear', 'payments',
            'fees', 'items', 'createdBy', 'cashAccount',
        ]);
        abort_unless($invoice->student?->status === 'pre_registered', 404);

        return view('dashboard.quick-registration.summary', compact('invoice'));
    }
}

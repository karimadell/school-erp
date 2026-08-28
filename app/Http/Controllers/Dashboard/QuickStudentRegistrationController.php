<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuickStudentRegistrationRequest;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\MealPlan;
use App\Models\PaymentPlan;
use App\Models\Stage;
use App\Models\Student;
use App\Services\Admissions\QuickStudentRegistrationService;
use App\Services\Finance\FinanceConfigurationReadinessService;
use App\Services\Finance\InvoiceCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QuickStudentRegistrationController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage invoices');
    }

    public function create(FinanceConfigurationReadinessService $readiness, InvoiceCalculationService $calculator): View
    {
        $academicYears = AcademicYear::where('is_active', true)->orderByDesc('start_date')->get();
        $modes = EnrollmentMode::active()->ordered()->get();
        // Scoped to the academic years this screen actually offers — a
        // stale prior-year or wrong-year price must never appear as if it
        // were an available tariff (grade_group/payment_period dropdowns
        // still read $fee->prices directly for their option lists; readiness
        // below is the single source for whether a service is selectable).
        // Phase 4A.2: filtered through InvoiceCalculationService::
        // resolvableCandidates() — academic_year_id is the ownership
        // boundary, not the calendar date; a sole same-year candidate is
        // offered even before its own start_date, several same-year
        // candidates are narrowed by date — the exact same rule the
        // resolver itself applies, never a separate UI-only date scope.
        $academicYearIds = $academicYears->pluck('id');
        $today = now()->toDateString();
        $fees = Fee::with(['prices' => fn ($query) => $query
                ->active()->where('currency', 'EGP')
                ->whereIn('academic_year_id', $academicYearIds)
                ->orderByDesc('start_date')])
            ->active()->orderBy('category')->orderBy('name_ru')->get();
        $fees->each(fn (Fee $fee) => $fee->setRelation('prices', $calculator->resolvableCandidates($fee->prices, $today)));

        // Phase 3: readiness is computed once here, against the screen's
        // primary active academic year, and handed to the view as data —
        // the blade no longer re-derives "is this fee sellable" itself.
        $primaryYear = $academicYears->first();
        $serviceReadiness = $primaryYear ? $readiness->forFees($fees, $primaryYear) : collect();

        // Phase 3, item 4: the uniform product catalog (a separate,
        // unmanaged table with no FK to fee_prices — see the Pricing Audit)
        // must not offer an item/size combination with no sellable tariff.
        // Filtered in memory against the same sellable() prices already
        // loaded above, so no second query and no risk of drifting from
        // what InvoiceCalculationService would actually resolve.
        $sellableUniformCombinations = $fees->where('category', Fee::CATEGORY_UNIFORM)
            ->flatMap(fn (Fee $fee) => $fee->prices)
            ->filter(fn (FeePrice $price) => filled($price->item) && filled($price->size))
            ->map(fn (FeePrice $price) => $price->item.'|'.$price->size)
            ->unique();
        $uniformProducts = DB::table('uniform_products')->where('is_active', true)->orderBy('name_ru')->orderBy('size')->get()
            ->filter(fn ($product) => $sellableUniformCombinations->contains($product->name_ru.'|'.$product->size))
            ->values();

        // Phase 4A.3: mirrors the uniform filtering above — a MealPlan must
        // not be offered unless a resolved Food tariff's option_value is
        // its exact numeric id. This excludes both legacy textual
        // option_value rows (e.g. "Напиток") and MealPlans with no
        // matching tariff at all, matching what FinanceConfigurationReadinessService
        // now requires for Food readiness — the dropdown and readiness can
        // never disagree.
        $sellableMealPlanIds = $fees->where('category', Fee::CATEGORY_FOOD)
            ->flatMap(fn (Fee $fee) => $fee->prices)
            ->pluck('option_value')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->unique();
        $mealPlans = MealPlan::active()->orderBy('name_ru')->get()
            ->filter(fn (MealPlan $plan) => $sellableMealPlanIds->contains($plan->id))
            ->values();

        return view('dashboard.quick-registration.create', [
            'academicYears' => $academicYears,
            'defaultAcademicYearId' => $academicYears->count() === 1 ? $academicYears->first()->id : null,
            'stages' => Stage::with([
                'grades' => fn ($query) => $query->ordered(),
                'grades.classes' => fn ($query) => $query->where('is_active', true)->orderBy('code'),
            ])
                ->where('is_active', true)->orderBy('order')->get(),
            'modes' => $modes,
            'defaultEnrollmentModeId' => $modes->count() === 1 ? $modes->first()->id : null,
            'fees' => $fees,
            'serviceReadiness' => $serviceReadiness,
            'installmentsReadiness' => $readiness->installments(),
            'mealPlans' => $mealPlans,
            'cashAccounts' => CashAccount::where('is_active', true)->excludingOwner()->orderBy('name')->get(),
            'transportRoutes' => DB::table('transport_routes')->orderBy('name')->get(),
            'uniformProducts' => $uniformProducts,
            'paymentPlans' => PaymentPlan::active()->with('installments')->orderBy('sort_order')->get(),
            'registrationSuccess' => $this->registrationSuccessFromSession(),
        ]);
    }

    public function store(
        StoreQuickStudentRegistrationRequest $request,
        QuickStudentRegistrationService $service,
    ): RedirectResponse {
        // TEMP DIAGNOSTIC (504 investigation, 2026-08-28) — remove after test.
        Context::add('qr_trace_id', (string) Str::uuid());
        Context::add('qr_start_at', microtime(true));
        Log::info('quick_registration.checkpoint', [
            'stage' => 'A_controller_store_entry',
            'trace_id' => Context::get('qr_trace_id'),
            'elapsed_ms' => 0,
        ]);

        $result = $service->register($request->validated(), $request->user());

        // TEMP DIAGNOSTIC — remove after test.
        Log::info('quick_registration.checkpoint', [
            'stage' => 'J_before_redirect',
            'trace_id' => Context::get('qr_trace_id'),
            'elapsed_ms' => round((microtime(true) - Context::get('qr_start_at', microtime(true))) * 1000, 1),
        ]);

        // Karim: Quick Registration must stay a single-page flow — issuing
        // the invoice already confirms the payment, so there is no separate
        // summary/receipt page to send the employee to. Redirect back to
        // this same screen; create() below swaps the form for an inline
        // success panel built from the just-created invoice.
        return redirect()->route('dashboard.quick-registration.create')
            ->with('registration_success_invoice_id', $result['invoice']->id);
    }

    /** @return ?array{invoice: Invoice, student: \App\Models\Student, payment: ?\App\Models\InvoicePayment} */
    private function registrationSuccessFromSession(): ?array
    {
        $invoiceId = session('registration_success_invoice_id');
        if (! $invoiceId) {
            return null;
        }

        $invoice = Invoice::with(['student', 'payments', 'cashAccount'])->find($invoiceId);
        if (! $invoice || $invoice->student?->status !== Student::STATUS_PRE_REGISTERED) {
            return null;
        }

        return [
            'invoice' => $invoice,
            'student' => $invoice->student,
            'payment' => $invoice->payments->sortByDesc('id')->first(),
        ];
    }

    public function price(Request $request, InvoiceCalculationService $calculator): JsonResponse
    {
        $data = $request->validate([
            'fee_id' => ['required', 'integer', 'exists:fees,id'],
            'fee_price_id' => ['nullable', 'integer', 'exists:fee_prices,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'item' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:50'],
            'option_type' => ['nullable', 'string', 'max:100'],
            'option_value' => ['nullable', 'string', 'max:255'],
            'transport_area' => ['nullable', 'string', 'max:150'],
            'grade_id' => ['nullable', 'integer', 'exists:grades,id'],
            'grade_group' => ['nullable', Rule::in(FeePrice::GRADE_GROUPS)],
            'payment_period' => ['nullable', 'string', 'max:50'],
            'first_last_month' => ['nullable', 'boolean'],
            'meal_plan_id' => ['nullable', 'integer', 'exists:meal_plans,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'enrollment_mode_id' => ['required', 'integer', 'exists:enrollment_modes,id'],
            'pricing_date' => ['nullable', 'date'],
            'registration_date' => ['nullable', 'date'],
        ]);
        $fee = Fee::findOrFail($data['fee_id']);
        $year = AcademicYear::findOrFail($data['academic_year_id']);
        $mode = EnrollmentMode::active()->findOrFail($data['enrollment_mode_id']);
        $pricingDate = isset($data['pricing_date']) || isset($data['registration_date'])
            ? \Illuminate\Support\Carbon::parse($data['pricing_date'] ?? $data['registration_date'])
            : now();
        $tuitionCategories = [
            Fee::CATEGORY_TUITION, Fee::CATEGORY_TUITION_REGULAR,
            Fee::CATEGORY_TUITION_FAMILY, Fee::CATEGORY_TUITION_EXTERNAL,
        ];
        $calculation = $calculator->calculate(items: [[
            'fee_id' => $fee->id,
            'fee_price_id' => $data['fee_price_id'] ?? null,
            'quantity' => $data['quantity'],
            'enrollment_mode_id' => $mode->id,
            'grade_id' => in_array($fee->category, $tuitionCategories, true) && blank($data['grade_group'] ?? null)
                ? ($data['grade_id'] ?? null)
                : null,
            'grade_group' => $data['grade_group'] ?? null,
            'payment_period' => $data['payment_period'] ?? null,
            'first_last_month' => (bool) ($data['first_last_month'] ?? false),
            'item' => $fee->category === Fee::CATEGORY_UNIFORM ? ($data['item'] ?? null) : null,
            'size' => $fee->category === Fee::CATEGORY_UNIFORM ? ($data['size'] ?? null) : null,
            'option_type' => $data['option_type'] ?? match ($fee->category) {
                Fee::CATEGORY_TRANSPORT => filled($data['transport_area'] ?? null) ? 'zone' : null,
                Fee::CATEGORY_FOOD => filled($data['meal_plan_id'] ?? null) ? 'meal_plan' : null,
                default => null,
            },
            'option_value' => $data['option_value'] ?? match ($fee->category) {
                Fee::CATEGORY_TRANSPORT => $data['transport_area'] ?? null,
                Fee::CATEGORY_FOOD => isset($data['meal_plan_id']) ? (string) $data['meal_plan_id'] : null,
                default => null,
            },
        ]], pricingDate: $pricingDate->toDateString(), academicYearId: (int) $data['academic_year_id']);

        return response()->json([
            'unit_price' => $calculation['line_items'][0]['unit_price'],
            'amount' => $calculation['line_items'][0]['amount'],
            'currency' => InvoiceCalculationService::CURRENCY,
            'valid_from' => $calculation['line_items'][0]['tariff_valid_from'],
            'valid_to' => $calculation['line_items'][0]['tariff_valid_to'],
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

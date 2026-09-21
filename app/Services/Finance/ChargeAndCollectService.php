<?php

namespace App\Services\Finance;

use App\Exceptions\DuplicateOpenInvoiceException;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\ServiceCoverage;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Models\User;
use App\Services\StudentServiceSubscriptionService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Phase 2 — cashier "charge & collect".
 *
 * Composes the two canonical services (issue an invoice, then record a
 * payment) into a single atomic action so a front-desk cashier can charge a
 * student for a not-yet-invoiced service and take the money in one controlled
 * step — the safe replacement for the disabled orphan-cash path. No new
 * pricing, numbering, or balance logic is introduced here.
 *
 * The whole operation runs in one transaction: if collection fails (e.g.
 * over-payment, inactive cash account) the freshly issued invoice is rolled
 * back too, so there is never an orphan invoice or orphan cash movement.
 *
 * Existing-student Food purchase corrective pass: Food is structurally
 * unlike every other category here — a student may legitimately hold many
 * Food invoices over a year (one per purchased period), so the generic
 * duplicate-open-invoice guard below (built for "never two open invoices for
 * the same service") is the WRONG protection for it and is bypassed in
 * favour of a genuine date-range overlap guard against this student's own
 * ServiceCoverage history. Every other category's guard, issuance shape, and
 * payment recording are completely untouched. $data['items'][0]['_fee_category']
 * (stamped by StoreChargeAndCollectRequest, which already looked the Fee up
 * once for its own payment_type decision) is read here instead of querying
 * Fee again — this service never re-resolves it.
 *
 * @phpstan-type ChargeResult array{invoice: \App\Models\Invoice, payment: ?InvoicePayment}
 */
class ChargeAndCollectService
{
    public function __construct(
        private InvoiceIssuanceService $issuer,
        private InvoicePaymentService $payments,
        private FoodBillableDayCalculator $foodDays,
        private StudentServiceSubscriptionService $subscriptions,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  Validated charge-and-collect data.
     * @return array{invoice: \App\Models\Invoice, payment: ?InvoicePayment}
     */
    public function chargeAndCollect(Student $student, array $data, User $actor, ?string $ip = null, ?string $userAgent = null): array
    {
        return DB::transaction(function () use ($student, $data, $actor, $ip, $userAgent) {
            $isFood = ($data['items'][0]['_fee_category'] ?? null) === Fee::CATEGORY_FOOD;
            // Derived once, reused both as the overlap guard's own replay
            // check and as issue()'s invoice-level idempotency key — a
            // genuine same-key retry must replay the original invoice, never
            // be rejected by the overlap guard as if it were a brand new,
            // conflicting purchase for the same dates.
            $foodInvoiceIdempotencyKey = $isFood && filled($data['idempotency_key'] ?? null)
                ? (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "charge-and-collect-invoice:{$data['idempotency_key']}")
                : null;

            if ($isFood) {
                // Existing-student Food purchase: many non-overlapping Food
                // invoices per student are the normal, expected shape (buy a
                // day, then a week, then extend a month later) — refused only
                // when the requested period genuinely overlaps coverage this
                // student already holds for this same Food service. A retry
                // of the SAME submission (same idempotency key, already
                // issued) is not a new conflicting purchase — skipped here so
                // issue()'s own idempotency replay below can run instead.
                $alreadyIssued = $foodInvoiceIdempotencyKey !== null
                    && Invoice::query()->where('idempotency_key', $foodInvoiceIdempotencyKey)->exists();
                if (! $alreadyIssued) {
                    $this->guardAgainstOverlappingFoodCoverage($student, $data);
                }
            } else {
                // 0) Narrow duplicate-open-invoice guard: refuse to issue a
                //    second collectible invoice for the same student +
                //    academic year + service. The cashier should collect
                //    against the existing unpaid/partially-paid invoice
                //    instead. Void/cancelled and fully-paid invoices never
                //    block. Registration-fee uniqueness remains a separate,
                //    independent check inside issuance. Unchanged for every
                //    non-Food category.
                $this->guardAgainstDuplicateOpenInvoice($student, $data);
            }

            // 1) Issue the invoice through the canonical path (tariff pricing,
            //    numbering, items, installment, registration-fee protection,
            //    audit) — unchanged behaviour for non-Food. For Food, this is
            //    the exact same FoodBillableDayCalculator/InvoiceCalculationService/
            //    ServiceCoverage machinery Quick Registration's own Food
            //    purchase already uses — no second calculation engine.
            $invoice = $this->issuer->issue(
                $student, $data, $actor, $ip, $userAgent,
                subscriptionResolver: $isFood ? $this->foodSubscriptionResolver($actor) : null,
                // A retried double-submit must never create a second Food
                // invoice: since the duplicate-open-invoice guard (the
                // mechanism every other category relies on for this) is
                // deliberately bypassed above, Food gets its own explicit
                // invoice-level idempotency key instead, derived from the
                // same client idempotency_key already required on every
                // Charge & Collect submission — a genuine retry replays the
                // original invoice instead of issuing a second one. Every
                // non-Food category is unaffected (idempotencyKey stays
                // null, exactly as before this pass).
                idempotencyKey: $foodInvoiceIdempotencyKey,
            );

            // 2) Optionally collect payment against the just-issued invoice.
            //    A zero amount means "charge only" (issue without collecting).
            //    Food always issues as a single InvoiceItem with a single
            //    dedicated installment (createFoodInstallmentAndCoverage()),
            //    so this same flat, non-installment-targeted record() call
            //    already resolves to it unambiguously — no special-casing
            //    needed here.
            $payment = null;
            $collect = bcadd((string) ($data['collect_amount'] ?? '0'), '0', 2);
            if (bccomp($collect, '0.00', 2) > 0) {
                $payment = $this->payments->record(
                    invoiceId: $invoice->id,
                    cashAccountId: CashAccount::resolvePaymentAccountId((string) $data['payment_method'], isset($data['cash_account_id']) ? (int) $data['cash_account_id'] : null),
                    amount: $collect,
                    paymentMethod: (string) $data['payment_method'],
                    idempotencyKey: (string) $data['idempotency_key'],
                    actor: $actor,
                    reference: 'Оплата при начислении '.$invoice->display_number,
                );
            }

            return ['invoice' => $invoice->fresh(), 'payment' => $payment];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws DuplicateOpenInvoiceException
     */
    private function guardAgainstDuplicateOpenInvoice(Student $student, array $data): void
    {
        $feeId = (int) collect($data['items'] ?? [])->pluck('fee_id')->first();
        if ($feeId <= 0) {
            return;
        }

        $existing = Invoice::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', (int) $data['academic_year_id'])
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->whereHas('items', fn ($query) => $query->where('fee_id', $feeId))
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            throw new DuplicateOpenInvoiceException(
                $existing->id,
                (string) $existing->display_number,
                'По этой услуге уже есть неоплаченный счёт. Примите оплату по существующему счёту.',
            );
        }
    }

    /**
     * Food-specific replacement for guardAgainstDuplicateOpenInvoice() above:
     * resolves the requested period through the SAME canonical
     * FoodBillableDayCalculator::resolveFromDurationSelection() entry point
     * real issuance uses (never re-derived, never a second calculation), then
     * checks it against every ServiceCoverage this student already holds for
     * this same Food Fee AND this same MealPlan, drawn from any invoice that
     * is not cancelled (a voided Food invoice's coverage never blocks a
     * legitimate re-purchase for the same dates).
     *
     * Owner-approved Stolovaya P1 corrective: a student may legitimately buy
     * several DIFFERENT meal plans on the same day (e.g. Обед + Напиток) —
     * every Food MealPlan shares one Fee, so scoping this guard by fee_id
     * alone treated any two different meals on the same date as the same
     * purchase. The identity is corrected to fee + MealPlan, matched via the
     * `option_value` ServiceCoverageService::recordWithBasisPrice() already
     * stamps onto every Food coverage row from the resolved FeePrice's own
     * option_value (the Phase 4B canonical MealPlan-id identity) — no new
     * column, no migration, since this identity was already persisted and
     * simply not read here. Only an EXACT match on the same MealPlan still
     * blocks (Обед + Обед same date) — this is unchanged for the
     * single-meal-plan-per-purchase shape every other Food entry point
     * (Charge & Collect's own Add Service form, Quick Registration, Unified
     * Collection) already has, since they only ever submit one meal_plan_id
     * per call, so this correction is transparent to them.
     *
     * An overlap fails safely — the same DuplicateOpenInvoiceException type
     * the controller already knows how to render (a friendly Russian message
     * naming the conflicting meal plus a direct link to the conflicting
     * invoice), never an HTTP 500, and never a partially persisted
     * Invoice/Payment/ServiceCoverage (this runs before issuance, inside the
     * same transaction).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws DuplicateOpenInvoiceException
     */
    private function guardAgainstOverlappingFoodCoverage(Student $student, array $data): void
    {
        $item = $data['items'][0] ?? null;
        $feeId = (int) ($item['fee_id'] ?? 0);
        if (! $item || $feeId <= 0) {
            return;
        }

        $year = AcademicYear::findOrFail((int) $data['academic_year_id']);
        $resolution = $this->foodDays->resolveFromDurationSelection($year, $item);

        // Always populated for a Food item — StoreChargeAndCollectRequest
        // requires meal_plan_id for Food and stamps it here as option_value
        // (the same string ServiceCoverage.option_value ends up holding),
        // so this is a real equality match against already-persisted data,
        // never a fuzzy/derived one.
        $mealPlanOptionValue = (string) ($item['option_value'] ?? '');

        $overlap = ServiceCoverage::query()
            ->where('student_id', $student->id)
            ->where('fee_id', $feeId)
            ->where('option_value', $mealPlanOptionValue)
            // whereDate() (not a plain where()) — coverage_start/coverage_end
            // are stored with a time component, so a raw string '<='/'>='
            // against a date-only value ("2026-09-01" vs "2026-09-01
            // 00:00:00") compares lexicographically wrong at the exact
            // boundary (the longer, time-suffixed string sorts AFTER the
            // shorter date-only one). whereDate() strips the time part on
            // both sides before comparing, so an identical-day overlap is
            // never missed.
            ->whereDate('coverage_start', '<=', $resolution['coverage_end'])
            ->whereDate('coverage_end', '>=', $resolution['coverage_start'])
            ->whereHas('invoiceItem.invoice', fn ($query) => $query->where('status', '!=', Invoice::STATUS_CANCELLED))
            ->with('invoiceItem.invoice')
            ->orderByDesc('id')
            ->first();

        if ($overlap) {
            $existingInvoice = $overlap->invoiceItem->invoice;
            $mealPlanName = MealPlan::query()->find($item['meal_plan_id'] ?? null)?->name_ru ?? 'Выбранное питание';
            throw new DuplicateOpenInvoiceException(
                $existingInvoice->id,
                (string) $existingInvoice->display_number,
                "«{$mealPlanName}» на выбранную дату уже оформлено для этого ученика (счёт {$existingInvoice->display_number}). Выберите другую дату/питание или примите оплату по существующему счёту.",
            );
        }
    }

    /**
     * Mirrors QuickStudentRegistrationService's own Food subscriptionResolver
     * closure exactly (same StudentServiceSubscriptionService::subscribe()
     * call, same MealSubscription creation) — the only place in this service
     * that knows about either. InvoiceIssuanceService::issue() only ever
     * invokes this when the student's enrollment has no already-active
     * subscription for this Fee (see its own $activeSubscriptionsByFee
     * lookup) — i.e. exactly once, on this student's very first Food
     * purchase; every subsequent Food purchase (extension, a new
     * non-overlapping period, etc.) reuses that same subscription id
     * automatically and never calls this closure again.
     */
    private function foodSubscriptionResolver(User $actor): Closure
    {
        return function (Fee $fee, array $selection, Enrollment $enrollment) use ($actor) {
            $mealPlan = MealPlan::query()->where('is_active', true)->find($selection['meal_plan_id'] ?? null);
            if (! $mealPlan) {
                throw ValidationException::withMessages(['meal_plan_id' => 'Выбранный план питания больше не доступен.']);
            }

            $coverageStart = $selection['food_resolution']['coverage_start'] ?? now()->toDateString();
            $subscription = $this->subscriptions->subscribe($enrollment, $fee, [
                'start_date' => $coverageStart,
                'quantity' => (int) ($selection['quantity'] ?? 1),
                'status' => StudentServiceSubscription::STATUS_ACTIVE,
                'metadata' => ['meal_plan_id' => $mealPlan->id, 'meal_plan' => $mealPlan->name_ru],
            ], $actor);

            MealSubscription::create([
                'enrollment_id' => $enrollment->id,
                'meal_plan_id' => $mealPlan->id,
                'start_date' => $coverageStart,
            ]);

            return $subscription->id;
        };
    }
}

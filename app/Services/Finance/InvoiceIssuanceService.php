<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FeeBillingPeriod;
use App\Models\FeePrice;
use App\Models\InstallmentCoveragePeriod;
use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\InvoiceItem;
use App\Models\PaymentPlan;
use App\Models\ServiceCoverage;
use App\Models\Student;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Canonical persistence path for issuing a student invoice.
 *
 * Extracted verbatim from StudentInvoiceController::store() so the same
 * transactional issuance logic can be reused (e.g. by mass billing) without
 * duplicating it. Behaviour is intentionally unchanged: academic-year
 * consistency, enrollment validation, server-side tariff resolution, invoice
 * numbering, invoice-item snapshots, compatibility invoice_fee rows,
 * installment creation, registration-fee duplicate protection, audit logging,
 * totals, EGP currency rules, and the transaction/row-lock behaviour are all
 * preserved exactly.
 *
 * The invoice's issue date continues to be carried by created_at (set to the
 * selected pricing date); there is no separate issue_date column because that
 * field already serves that role for existing records.
 */
class InvoiceIssuanceService
{
    public function __construct(
        private InvoiceCalculationService $calculator,
        private InstallmentPlanService $plans,
        private ServiceCoverageService $coverage,
        private FoodBillableDayCalculator $foodDays,
    ) {
    }

    /**
     * Issue an invoice for the given student from validated request data.
     *
     * @param  array<string, mixed>  $data  Output of StoreInvoiceRequest::validated().
     * @param  ?Closure(Fee, array<string, mixed>, Enrollment): ?int  $subscriptionResolver
     *         Invoked once per line item that has no existing active
     *         StudentServiceSubscription, so the caller can create one and
     *         return its id to attach — this service deliberately has no
     *         knowledge of StudentServiceSubscriptionService, MealSubscription,
     *         or any other Admissions-domain concern; that stays entirely
     *         with the caller's resolver. Passing null (the default)
     *         preserves the original behaviour of only ever linking an
     *         already-existing subscription, never creating one.
     * @param  ?string  $origin  Which flow issued this invoice (see
     *         Invoice::ORIGIN_* constants) — Phase 1, Quick Registration
     *         document semantics only. Optional and untracked (null) for
     *         every other caller; passing null preserves the original
     *         behaviour of not recording an origin at all.
     * @param  ?string  $idempotencyKey  Finance V2, Phase 2D corrective
     *         pass (P0/HIGH — invoice issuance idempotency). A UUID,
     *         stable across a retried submission of the same intended
     *         issuance (Quick Registration threads its own per-page-render
     *         idempotency_token through here, deterministically derived,
     *         same convention as InvoicePaymentService's payment-level
     *         key). When provided and an Invoice with this exact key
     *         already exists, that SAME invoice is returned directly — no
     *         new Invoice/Item/Installment/ServiceCoverage/
     *         InstallmentCoveragePeriod is created. Null (the default)
     *         preserves the original behaviour of every existing caller
     *         that doesn't pass one — always a fresh issuance, exactly as
     *         before this phase.
     */
    public function issue(Student $student, array $data, User $actor, ?string $ip = null, ?string $userAgent = null, ?Closure $subscriptionResolver = null, ?string $origin = null, ?string $idempotencyKey = null): Invoice
    {
        if ((int) $data['student_id'] !== $student->id) {
            throw ValidationException::withMessages(['student_id' => 'Выбранный ученик не соответствует адресу формы.']);
        }
        if ($idempotencyKey !== null && ! \Illuminate\Support\Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Укажите корректный ключ повторного запроса.']);
        }
        // Corrective pass #3 (HIGH 1 — direct InvoiceIssuanceService
        // idempotency hash). issue() has callers besides Quick
        // Registration (Classic Invoice via StudentInvoiceController,
        // among others) — this is its OWN canonical payload hash, never
        // derived from or coupled to QuickStudentRegistrationService's
        // operation-level one. See canonicalPayloadHash()'s own
        // docblock for exactly what is covered and what is deliberately
        // excluded.
        $idempotencyHash = $idempotencyKey !== null
            ? $this->canonicalPayloadHash($student, $data, $origin)
            : null;

        // Checked BEFORE opening the transaction too — the overwhelmingly
        // common case (a genuine first-time submission) never even opens
        // one for this check, and a replay short-circuits immediately.
        if ($idempotencyKey !== null) {
            $existing = Invoice::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->replayInvoice($existing, $idempotencyHash);
            }
        }

        // Corrective pass #2 (HIGH 1 — PostgreSQL-safe concurrent
        // idempotency). On PostgreSQL, once ANY statement inside a
        // transaction raises an error (a unique-violation on the
        // idempotency_key insert below included), the WHOLE transaction
        // enters an aborted state — every subsequent statement in that
        // same transaction fails with "current transaction is aborted,
        // commands ignored until end of transaction block," even a
        // harmless SELECT. SQLite has no such failure mode, which is
        // exactly why catching the violation and querying again INSIDE
        // the same DB::transaction() closure (this method's own pass #1
        // shape) passed every test here without ever exposing the bug.
        //
        // Fix: the unique-violation is never caught inside the closure —
        // it propagates out, Laravel's DB::transaction() rolls back
        // cleanly (a normal, non-aborted rollback), and ONLY THEN, in
        // this outer, non-transactional scope, do we catch it and query
        // for the winning row — a fresh query, on a fresh connection
        // state, never inside the now-dead transaction. The database's
        // own unique constraint remains the final arbiter of who won the
        // race; this only changes WHERE the recovery query runs.
        try {
            return DB::transaction(function () use ($data, $student, $actor, $ip, $userAgent, $subscriptionResolver, $origin, $idempotencyKey, $idempotencyHash) {
            // Re-checked once more, now serialized by the student row lock
            // immediately below — closes the race window between the
            // pre-transaction check above and this transaction acquiring
            // its locks (two concurrent requests could both have passed
            // the first check together).
            if ($idempotencyKey !== null) {
                $existing = Invoice::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return $this->replayInvoice($existing, $idempotencyHash);
                }
            }

            $student = Student::query()->lockForUpdate()->findOrFail($student->id);
            $year = AcademicYear::query()->lockForUpdate()->findOrFail($data['academic_year_id']);
            $enrollment = Enrollment::query()->where('student_id', $student->id)->where('academic_year_id', $year->id)->where('is_active', true)->lockForUpdate()->first();
            if (! $year->is_active || ! $enrollment) {
                throw ValidationException::withMessages(['academic_year_id' => 'Активное зачисление на выбранный учебный год не найдено.']);
            }

            $registrationFeeIds = Fee::whereIn('id', collect($data['items'])->pluck('fee_id'))->where('category', Fee::CATEGORY_REGISTRATION)->pluck('id');
            if ($registrationFeeIds->isNotEmpty() && InvoiceItem::whereHas('invoice', fn ($query) => $query->where('student_id', $student->id)->where('academic_year_id', $year->id))->whereIn('fee_id', $registrationFeeIds)->exists()) {
                throw ValidationException::withMessages(['fees' => 'Регистрационный взнос уже начислен ученику за этот учебный год.']);
            }

            // Perf (504 investigation, 2026-08-29): Fee was previously reloaded
            // with a fresh findOrFail() per line item here, and again per line
            // item in the post-creation loop below — 2 queries per line on top
            // of InvoiceCalculationService's own batched fetch. One batched
            // fetch, reused by both loops, removes that duplication without
            // changing the not-found behaviour (still throws
            // ModelNotFoundException for an unknown fee_id).
            $feesById = Fee::whereIn('id', collect($data['items'])->pluck('fee_id')->unique())->get()->keyBy('id');
            $resolveFee = function (int $feeId) use ($feesById): Fee {
                return $feesById->get($feeId) ?? throw (new ModelNotFoundException())->setModel(Fee::class, [$feeId]);
            };

            $items = collect($data['items'])->map(function (array $item) use ($enrollment, $resolveFee) {
                $fee = $resolveFee((int) $item['fee_id']);
                if (in_array($fee->category, [Fee::CATEGORY_TUITION, Fee::CATEGORY_TUITION_REGULAR, Fee::CATEGORY_TUITION_FAMILY, Fee::CATEGORY_TUITION_EXTERNAL], true)
                    && blank($item['grade_group'] ?? null)) {
                    $item['grade_id'] = $enrollment->grade_id;
                }
                $item['enrollment_mode_id'] = $enrollment->enrollment_mode_id;

                return $item;
            })->all();
            // Discount fields are part of the shared StoreInvoiceRequest shape
            // (used by both the classic and per-student create screens) but
            // were previously only ever honoured by the classic controller's
            // own inline calculation. Reading them here — they are null/absent
            // for every caller that never offered a discount UI — makes the
            // classic screen's discount feature survive its migration onto
            // this service instead of being silently dropped.
            //
            // Phase 2D corrective pass (P0 Blocker 1): for payment_type
            // 'calendar', the billing_period must be resolved and passed
            // into calculate() BEFORE pricing, so every line's unit price
            // is correctly multiplied by its covered period count — this
            // is what fixes the previously-unscaled (severely underbilled)
            // invoice totals. The per-Fee allowsBillingPeriod() eligibility
            // check still happens later (needs $invoiceFees, resolved
            // after item creation below) — this only validates the
            // billing_period value itself is a real calendar period.
            // Food is entirely decoupled from CalendarPeriodCalculator's
            // month/quarter grouping (which exists only for Tuition/
            // Transport's calendar billing_period concept and structurally
            // cannot represent a 1-day/1-week/N-teaching-day/custom-range
            // Food purchase — CalendarPeriodCalculator::resolve()
            // unconditionally snaps its period start to startOfMonth()).
            // billing_period is therefore required only when a non-Food
            // calendar-billed Fee is ALSO on the invoice; a Food-only
            // invoice needs no billing_period at all.
            $hasFood = collect($items)->contains(fn (array $item) => $resolveFee((int) $item['fee_id'])->category === Fee::CATEGORY_FOOD);
            $hasNonFoodItems = collect($items)->contains(fn (array $item) => $resolveFee((int) $item['fee_id'])->category !== Fee::CATEGORY_FOOD);
            if ($hasFood && ($data['payment_type'] ?? null) !== 'calendar') {
                throw ValidationException::withMessages(['payment_type' => 'Питание оформляется только с явным периодом обслуживания.']);
            }
            $calendarBillingPeriod = null;
            if (($data['payment_type'] ?? null) === 'calendar' && $hasNonFoodItems) {
                $calendarBillingPeriod = $data['billing_period'] ?? null;
                if (! in_array($calendarBillingPeriod, FeeBillingPeriod::CALENDAR_PERIODS, true)) {
                    throw ValidationException::withMessages(['billing_period' => 'Укажите период оплаты.']);
                }
            }
            $calendarStart = $data['coverage_start'] ?? $data['pricing_date'];
            $calendarEnd = $data['coverage_end'] ?? ($calendarBillingPeriod !== null ? $year->end_date->toDateString() : null);

            // Each Food item resolves its OWN [start,end] range directly
            // from its submitted duration-mode selection via the single
            // canonical FoodBillableDayCalculator::resolveFromDurationSelection()
            // entry point — the SAME one the live-pricing preview endpoint
            // (QuickStudentRegistrationController::price()) calls, so
            // preview and real issuance can never structurally disagree.
            // Resolved once here and attached to the item so it feeds both
            // pricing (InvoiceCalculationService::calculate(), below) and,
            // later, ServiceCoverage/InstallmentCoveragePeriod creation
            // (createFoodInstallmentAndCoverage()) — never re-derived.
            if ($hasFood) {
                $items = collect($items)->map(function (array $item) use ($resolveFee, $year) {
                    if ($resolveFee((int) $item['fee_id'])->category === Fee::CATEGORY_FOOD) {
                        $item['food_resolution'] = $this->foodDays->resolveFromDurationSelection($year, $item);
                    }

                    return $item;
                })->all();
            }

            $calculation = $this->calculator->calculate(
                $items, $data['discount_type'] ?? null, $data['discount_value'] ?? null, '0', $data['pricing_date'], $year->id,
                $calendarBillingPeriod, $calendarEnd, $calendarStart,
            );
            $invoiceData = [
                'student_id'=>$student->id, 'academic_year_id'=>$year->id, 'customer_name'=>$student->full_name,
                'currency'=>'EGP', 'subtotal_amount'=>$calculation['subtotal'], 'total_amount'=>$calculation['total_amount'],
                'discount_type'=>$data['discount_type'] ?? null, 'discount_value'=>$data['discount_value'] ?? '0.00',
                'discount_amount'=>$calculation['discount_amount'], 'paid_amount'=>'0.00', 'remaining_amount'=>$calculation['total_amount'],
                'status'=>$calculation['status'], 'due_date'=>$data['due_date'],
                'created_by'=>$actor->id,
            ];
            if (Schema::hasColumn('invoices', 'note')) {
                $invoiceData['note'] = $data['notes'] ?? null;
            }
            if ($origin !== null && Schema::hasColumn('invoices', 'origin')) {
                $invoiceData['origin'] = $origin;
            }
            if ($idempotencyKey !== null) {
                $invoiceData['idempotency_key'] = $idempotencyKey;
                $invoiceData['idempotency_hash'] = $idempotencyHash;
            }
            $invoice = new Invoice($invoiceData);
            $invoice->created_at = Carbon::parse($data['pricing_date'])->startOfDay();
            // HIGH 1: no try/catch here — a genuine concurrent race (two
            // simultaneous requests with the same idempotency_key both
            // passing the checks above before either committed) is
            // recovered OUTSIDE this transaction, once it has already
            // rolled back cleanly. See this method's own top-level
            // try/catch below.
            $invoice->save();
            $invoice->invoice_number = Invoice::numberFor($invoice->id, $invoice->created_at->format('Y'));
            $invoice->save();

            // Perf (504 investigation, 2026-08-29): pre-load every active
            // subscription for this enrollment once instead of one
            // `->where('fee_id', ...)->first()` query per line item. Updated
            // in-loop (not just read) so a fee_id repeated across two line
            // items — same edge case the original per-item query handled —
            // still sees the subscription created for the first occurrence.
            $activeSubscriptionsByFee = $enrollment->serviceSubscriptions()->where('status', 'active')->get()->keyBy('fee_id');
            // Batched into one attach() call after the loop when every line
            // has a distinct fee_id (the overwhelming common case — the Quick
            // Registration UI can't submit the same fee twice). If a caller's
            // payload ever does repeat a fee_id, fall back to the original
            // one-attach()-per-line behaviour so pivot rows are never
            // silently collapsed.
            $feePivotRows = [];
            $hasDuplicateFeeLines = collect($calculation['line_items'])->pluck('fee_id')->duplicates()->isNotEmpty();
            // Finance V2, Phase 2D: the just-created InvoiceItem per Fee,
            // needed below to wire automatic ServiceCoverage creation for
            // calendar-billed invoices. Only meaningful when fee_id isn't
            // duplicated across lines (the automatic-coverage feature only
            // ever fires for Quick-Registration-shaped submissions, which
            // cannot repeat a fee_id — see the guard where it's consumed).
            $itemsByFeeId = [];
            // Corrective pass #2 (P0 Blocker 2): this Fee's own amount per
            // schedule group/period — same order as the schedule created
            // below — so each InstallmentCoveragePeriod row can carry its
            // OWN "full settlement" amount for THAT specific service.
            $periodAmountsByFeeId = [];

            foreach ($calculation['line_items'] as $line) {
                $periodAmountsByFeeId[$line['fee_id']] = $line['period_amounts'] ?? null;
                $selection = collect($items)->firstWhere('fee_id', $line['fee_id']);
                $fee = $resolveFee((int) $line['fee_id']);
                $subscriptionId = $activeSubscriptionsByFee->get($line['fee_id'])?->id;
                if (! $subscriptionId && $subscriptionResolver) {
                    $subscriptionId = $subscriptionResolver($fee, $selection, $enrollment);
                    $activeSubscriptionsByFee->put($line['fee_id'], (object) ['id' => $subscriptionId]);
                }
                $itemsByFeeId[$line['fee_id']] = InvoiceItem::create([
                    'invoice_id'=>$invoice->id, 'fee_id'=>$line['fee_id'], 'subscription_id'=>$subscriptionId,
                    'description'=>$line['description'], 'unit_price'=>$line['unit_price'], 'quantity'=>$line['quantity'],
                    'amount'=>$line['amount'], 'paid_amount'=>'0.00', 'remaining_amount'=>$line['amount'],
                    'is_non_refundable'=>$fee->is_non_refundable,
                    // Phase 2D: fee_price_id (needed by ServiceCoverageService::
                    // sourceTariff() for automatic coverage creation below) and
                    // the quarterly-derivation flags resolvePrice() may have set
                    // are carried on $line['metadata'] but were never previously
                    // pulled into the persisted InvoiceItem.metadata — only
                    // $selection (the raw submitted line) plus two specific
                    // calculation outputs were. Pulled explicitly here, same
                    // style as the existing tariff_valid_from/to pulls, rather
                    // than merging the whole $line['metadata'] blob (which could
                    // reintroduce keys $selection already sets differently).
                    'metadata'=>collect($selection)->except(['fee_id','quantity'])->merge([
                        'pricing_date'=>$data['pricing_date'], 'tariff_valid_from'=>$line['tariff_valid_from'], 'tariff_valid_to'=>$line['tariff_valid_to'],
                        'fee_price_id'=>$line['metadata']['fee_price_id'] ?? null,
                        'derived'=>$line['metadata']['derived'] ?? null,
                        'derived_period'=>$line['metadata']['derived_period'] ?? null,
                        'derived_from_period'=>$line['metadata']['derived_from_period'] ?? null,
                        'derived_from_fee_price_id'=>$line['metadata']['derived_from_fee_price_id'] ?? null,
                        'monthly_unit_amount'=>$line['metadata']['monthly_unit_amount'] ?? null,
                        'multiplier'=>$line['metadata']['multiplier'] ?? null,
                        // Phase 2D corrective pass (P0 Blocker 1) — auditable
                        // multi-period pricing trace, only present for
                        // calendar-billed lines.
                        'unit_tariff'=>$line['metadata']['unit_tariff'] ?? null,
                        'billing_unit'=>$line['metadata']['billing_unit'] ?? null,
                        'unit_count'=>$line['metadata']['unit_count'] ?? null,
                        'coverage_start'=>$line['metadata']['coverage_start'] ?? null,
                        'coverage_end'=>$line['metadata']['coverage_end'] ?? null,
                        'line_total'=>$line['metadata']['line_total'] ?? null,
                        // Corrective pass #2 (P0 Blocker 1) — partial
                        // trailing quarterly group audit trace, only
                        // present when an explicit quarterly package
                        // price covers a span that isn't an exact
                        // multiple of 3 months.
                        'partial_group_months'=>$line['metadata']['partial_group_months'] ?? null,
                        'partial_group_unit_price'=>$line['metadata']['partial_group_unit_price'] ?? null,
                        'partial_group_amount'=>$line['metadata']['partial_group_amount'] ?? null,
                        'partial_group_start'=>$line['metadata']['partial_group_start'] ?? null,
                        'partial_group_end'=>$line['metadata']['partial_group_end'] ?? null,
                        // Corrective pass #3 (HIGH 3 — quarterly <3-month
                        // and mixed-block line representation) — full
                        // audit trace of the requested strategy, how many
                        // complete quarterly package blocks actually
                        // applied, and (for the mixed case) the blended
                        // unit_price plus every block's own real amount.
                        'requested_billing_strategy'=>$line['metadata']['requested_billing_strategy'] ?? null,
                        'complete_quarterly_blocks'=>$line['metadata']['complete_quarterly_blocks'] ?? null,
                        'quarterly_package_applied'=>$line['metadata']['quarterly_package_applied'] ?? null,
                        'quarterly_package_price'=>$line['metadata']['quarterly_package_price'] ?? null,
                        'blended_unit_price'=>$line['metadata']['blended_unit_price'] ?? null,
                        'per_block_amounts'=>$line['metadata']['per_block_amounts'] ?? null,
                        'component_month_count'=>$line['metadata']['component_month_count'] ?? null,
                        // Corrective pass #4 (HIGH 1) — the ONE authoritative
                        // post-discount amount for this item; InvoiceItem.amount
                        // itself deliberately stays pre-discount (see
                        // InvoiceCalculationService::calculate()'s own
                        // docblock). Always present (never filtered out even
                        // when equal to amount, i.e. no discount) so
                        // reconciliation code never has to guess "was this
                        // invoice discounted."
                        'final_discounted_amount'=>$line['metadata']['final_discounted_amount'] ?? $line['amount'],
                        'pre_discount_amount'=>$line['metadata']['pre_discount_amount'] ?? null,
                        'allocated_discount_amount'=>$line['metadata']['allocated_discount_amount'] ?? null,
                        'display_unit_price'=>$line['metadata']['display_unit_price'] ?? null,
                        'display_quantity'=>$line['metadata']['display_quantity'] ?? null,
                        'food_billable_day_count'=>$line['metadata']['food_billable_day_count'] ?? null,
                        'food_excluded_day_count'=>$line['metadata']['food_excluded_day_count'] ?? null,
                        'food_calendar_id'=>$line['metadata']['food_calendar_id'] ?? null,
                        'food_calendar_rule_version'=>$line['metadata']['food_calendar_rule_version'] ?? null,
                        'food_tariff_segments'=>$line['metadata']['food_tariff_segments'] ?? null,
                        'food_coverage_start'=>$line['metadata']['food_coverage_start'] ?? null,
                        'food_coverage_end'=>$line['metadata']['food_coverage_end'] ?? null,
                        'food_duration_mode'=>$line['metadata']['food_duration_mode'] ?? null,
                        'food_requested_day_count'=>$line['metadata']['food_requested_day_count'] ?? null,
                    ])->filter(fn ($value) => filled($value))->all(),
                ]);
                $pivotData = [
                    'amount'=>$line['amount'], 'item'=>$line['item'], 'size'=>$line['size'],
                    'option_type'=>$line['option_type'], 'option_value'=>$line['option_value'],
                ];
                if ($hasDuplicateFeeLines) {
                    $invoice->fees()->attach($line['fee_id'], $pivotData);
                } else {
                    $feePivotRows[$line['fee_id']] = $pivotData;
                }
            }
            if ($feePivotRows) {
                $invoice->fees()->attach($feePivotRows);
            }

            // Finance V2, Phase 2B: $feesById already holds exactly the Fees
            // on this invoice (resolved above from $data['items']) — used
            // below to validate the chosen billing option is one every one
            // of them actually allows, never a blanket global option.
            //
            // Food flexible-duration corrective pass: Food Fees are always
            // excluded from $invoiceFees below and settled entirely through
            // their own dedicated createFoodInstallmentAndCoverage() call
            // instead — payment schedule stays a separate concept from
            // service coverage, and a multi-month Food purchase is never
            // automatically split into monthly installments just because
            // its resolved range happens to span several calendar months.
            $invoiceFees = $feesById->reject(fn (Fee $fee) => $fee->category === Fee::CATEGORY_FOOD);
            $foodFees = $feesById->filter(fn (Fee $fee) => $fee->category === Fee::CATEGORY_FOOD);

            if ($invoiceFees->isNotEmpty()) {
                if ($data['payment_type'] === 'plan') {
                    // Phase 2B fix: a PaymentPlan is only valid for this invoice
                    // if it is explicitly assigned to EVERY Fee being invoiced
                    // (and that Fee allows 'custom_plan') — never offered
                    // globally to any Fee regardless of assignment.
                    foreach ($invoiceFees as $fee) {
                        if (! $fee->allowsBillingPeriod(FeeBillingPeriod::PERIOD_CUSTOM_PLAN)
                            || ! $fee->assignedPaymentPlans()->where('payment_plans.id', $data['payment_plan_id'])->exists()) {
                            throw ValidationException::withMessages(['payment_plan_id' => "Выбранный план оплаты не назначен для услуги «{$fee->name_ru}»."]);
                        }
                    }
                    $plan = PaymentPlan::active()->lockForUpdate()->findOrFail($data['payment_plan_id']);
                    $this->plans->generate($invoice, $plan, $data['pricing_date']);
                } elseif ($data['payment_type'] === 'calendar' && $calendarBillingPeriod !== null) {
                    // Already resolved and format-validated above (before
                    // calculate()) — reused here, not re-derived, so pricing
                    // and scheduling can never see a different value.
                    $billingPeriod = $calendarBillingPeriod;
                    foreach ($invoiceFees as $fee) {
                        if (! $fee->allowsBillingPeriod($billingPeriod)) {
                            $periodLabel = FeeBillingPeriod::PERIOD_LABELS[$billingPeriod] ?? $billingPeriod;
                            throw ValidationException::withMessages(['billing_period' => "Услуга «{$fee->name_ru}» не поддерживает период оплаты «{$periodLabel}»."]);
                        }
                    }
                    // Corrective pass #2 (P0 Blocker 1 — partial final
                    // quarter): the shared per-group amounts calculate()
                    // already computed (unit price x each group's own
                    // month-count, never an even division) — passed straight
                    // through so scheduling can never disagree with pricing.
                    $schedule = $this->plans->generateCalendarSchedule($invoice, $billingPeriod, $calendarStart, $calendarEnd, $calculation['schedule_amounts'] ?? null, $calculation['scheduleable_total'] ?? null);

                    // Finance V2, Phase 2D corrective pass (P0 Blocker 2):
                    // coverage is now created for EVERY calendar billing
                    // period (monthly/quarterly/yearly), not just monthly —
                    // collection cadence and coverage tracking are separate
                    // concerns; ServiceCoverage always uses billing_unit=
                    // 'monthly'. Food is never passed here (see $invoiceFees
                    // above) — its own coverage is created by
                    // createFoodInstallmentAndCoverage() below instead.
                    $this->createAutomaticCoverage($billingPeriod, $schedule, $itemsByFeeId, $periodAmountsByFeeId, $invoiceFees, $actor, $year->id, $data['pricing_date']);
                } else {
                    $this->plans->generateSingle($invoice, $data['due_date']);
                }
            }

            foreach ($foodFees as $fee) {
                $this->createFoodInstallmentAndCoverage($invoice, $itemsByFeeId[$fee->id], $actor);
            }

            AuditLog::create(['user_id'=>$actor->id,'action'=>'created','model'=>'Invoice','model_id'=>$invoice->id,'new_values'=>['invoice_number'=>$invoice->invoice_number,'total_amount'=>$invoice->total_amount],'ip'=>$ip,'user_agent'=>$userAgent]);

            return $invoice;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            // HIGH 1: the transaction above has already rolled back
            // cleanly by the time this runs (Laravel rolls back
            // automatically when the closure throws) — this query runs
            // in a genuinely fresh transaction/connection state, never
            // inside the now-dead one, which is exactly what PostgreSQL
            // requires (see this method's own docblock note above).
            if ($idempotencyKey === null) {
                throw $exception;
            }

            return $this->replayInvoice(Invoice::query()->where('idempotency_key', $idempotencyKey)->firstOrFail(), $idempotencyHash);
        }
    }

    /**
     * Finance V2, Phase 2D corrective pass (items 2/3, P0 Blockers 2 & 3)
     * — automatic ServiceCoverage creation for EVERY calendar-billed
     * invoice (monthly, quarterly, and yearly — not just monthly). Runs
     * inside issue()'s own DB::transaction() (no second transaction
     * opened here), so an issuance rollback rolls this back too, and a
     * retried submission that hits the M3 idempotency fail-safe never
     * leaves an orphan coverage row.
     *
     * One ServiceCoverage per periodic Fee on the invoice, spanning the
     * FULL generated schedule (first period's start through last period's
     * end). One InstallmentCoveragePeriod per (installment, coverage)
     * pair — every Fee sharing this invoice's one schedule sees the same
     * period boundaries per installment (M1).
     *
     * billing_unit is ALWAYS 'monthly' (or 'daily' for Food), regardless
     * of the invoice's own collection cadence — collection cadence and
     * coverage-tracking granularity are separate facts. For a monthly-
     * billed non-Food Fee, the item's own already-resolved FeePrice IS
     * already monthly-denominated — used directly via the existing,
     * unchanged ServiceCoverageService::record() (identical to Stage B).
     * For quarterly/yearly billing, or Food at ANY billing cadence, the
     * item's own charged price is NOT monthly/daily-denominated (it's
     * quarterly/yearly, or a non-daily Food rate) — record()/sourceTariff()
     * hard-require the coverage tariff to equal the charged tariff exactly
     * (both payment_period AND unit_price), so it cannot be reused for
     * this case. A separate MONTHLY (or 'daily' for Food) "adjustment
     * basis" tariff is resolved instead via InvoiceCalculationService::
     * resolveCoverageBasisPrice() and passed to the new, narrower
     * ServiceCoverageService::recordWithBasisPrice() — see that method's
     * own docblock. adjustment_basis_* metadata is recorded on the
     * InvoiceItem for audit traceability.
     *
     * P0 Blocker 3: coverage is a financial invariant for every periodic-
     * billed Fee here — no try/catch. If ServiceCoverageService throws
     * (structural validation failure) or no basis price exists, the
     * exception propagates and the whole issuance transaction rolls back
     * — zero persisted rows, never a silent partial commit.
     *
     * @param  array<int, array{installment: \App\Models\InvoiceInstallment, period_start: string, period_end: string}>  $schedule
     * @param  array<int, InvoiceItem>  $itemsByFeeId
     * @param  \Illuminate\Support\Collection<int, Fee>  $invoiceFees
     */
    private function createAutomaticCoverage(string $billingPeriod, array $schedule, array $itemsByFeeId, array $periodAmountsByFeeId, \Illuminate\Support\Collection $invoiceFees, User $actor, int $academicYearId, string $pricingDate): void
    {
        if ($schedule === []) {
            return;
        }
        $coverageStart = $schedule[0]['period_start'];
        $coverageEnd = end($schedule)['period_end'];

        foreach ($invoiceFees as $fee) {
            $item = $itemsByFeeId[$fee->id] ?? null;
            if (! $item) {
                throw ValidationException::withMessages(['fees' => "Не удалось найти позицию счёта для услуги «{$fee->name_ru}» при создании покрытия."]);
            }
            $isFood = $fee->category === Fee::CATEGORY_FOOD;
            $billingUnit = $isFood ? 'daily' : 'monthly';

            if ($isFood && ! empty($item->metadata['food_tariff_segments'])) {
                $basisPrice = FeePrice::query()->lockForUpdate()->findOrFail((int) $item->metadata['food_tariff_segments'][0]['fee_price_id']);
                $coverage = $this->coverage->recordWithBasisPrice($item, $basisPrice, [
                    'coverage_start' => $coverageStart,
                    'coverage_end' => $coverageEnd,
                    'billing_unit' => 'daily',
                    'metadata' => [
                        'food_calendar_id' => $item->metadata['food_calendar_id'],
                        'food_calendar_rule_version' => $item->metadata['food_calendar_rule_version'],
                        'food_billable_day_count' => $item->metadata['food_billable_day_count'],
                        'food_tariff_segments' => $item->metadata['food_tariff_segments'],
                    ],
                ], $actor);
            } elseif (! $isFood && $billingPeriod === FeeBillingPeriod::PERIOD_MONTHLY && ($item->metadata['fee_price_id'] ?? null)) {
                // Fast, unchanged path: the item's own charged price is
                // already monthly-denominated — reuse it directly,
                // exactly like Stage B did.
                $coverage = $this->coverage->record($item, [
                    'fee_price_id' => $item->metadata['fee_price_id'],
                    'coverage_start' => $coverageStart,
                    'coverage_end' => $coverageEnd,
                    'billing_unit' => $billingUnit,
                ], $actor);
            } else {
                // Corrective pass #2 (HIGH 5): the FULL selection/metadata
                // — grade_id, enrollment_mode_id and every other canonical
                // dimension the item actually carries, not a hand-picked
                // subset — so basis-price resolution can never disagree
                // with primary price resolution about which dimensions
                // matter.
                $selection = $item->metadata ?? [];
                $basisPrice = $this->calculator->resolveCoverageBasisPrice($fee, $selection, $pricingDate, $academicYearId, $billingUnit);
                if (! $basisPrice) {
                    // Corrective pass #3 (P2 — Food remediation message
                    // clarity, no behavior change): the Food/daily case
                    // gets its own, more specific remediation text —
                    // pass #1's fail-closed behavior (roll back the
                    // whole issuance, never a silent partial commit) is
                    // unchanged; only the wording accountants see is
                    // clearer about exactly what to configure.
                    $message = $billingUnit === 'daily'
                        ? "Для услуги «{$fee->name_ru}» не настроен дневной тариф — питание требует: тариф в дневном исчислении, явную дневную базу для будущих корректировок, и согласованную политику расчёта дней/календаря, прежде чем периодическое начисление станет возможным."
                        : "Для услуги «{$fee->name_ru}» отсутствует базовый месячный тариф, необходимый для покрытия и будущих корректировок — настройте его перед оформлением.";
                    throw ValidationException::withMessages(['fees' => $message]);
                }

                $matchedDimensions = collect(['grade_id', 'grade_group', 'option_type', 'option_value', 'size', 'item', 'enrollment_mode_id'])
                    ->filter(fn ($field) => filled($selection[$field] ?? null))->values()->all();
                $item->forceFill(['metadata' => array_merge($item->metadata ?? [], [
                    'adjustment_basis_period' => $billingUnit,
                    'adjustment_basis_fee_price_id' => $basisPrice->id,
                    'adjustment_basis_unit_amount' => (string) $basisPrice->amount,
                    'adjustment_basis_matched_dimensions' => $matchedDimensions,
                ])])->save();

                $coverage = $this->coverage->recordWithBasisPrice($item, $basisPrice, [
                    'coverage_start' => $coverageStart,
                    'coverage_end' => $coverageEnd,
                    'billing_unit' => $billingUnit,
                ], $actor);
            }

            $periodAmounts = $periodAmountsByFeeId[$fee->id] ?? null;
            foreach ($schedule as $index => $period) {
                InstallmentCoveragePeriod::create([
                    'invoice_installment_id' => $period['installment']->id,
                    'service_coverage_id' => $coverage->id,
                    'period_start' => $period['period_start'],
                    'period_end' => $period['period_end'],
                    // Corrective pass #2 (P0 Blocker 2): this Fee's OWN
                    // charge for this specific period — never the shared
                    // installment total — so a later payment allocation
                    // can be compared against the correct "full
                    // settlement" figure for exactly this service/period.
                    'amount' => $periodAmounts[$index] ?? null,
                ]);
            }
        }
    }

    /**
     * Food flexible-duration corrective pass. Food's own dedicated
     * settlement: exactly ONE installment (a lump sum — a multi-month
     * Food purchase is never automatically split into monthly
     * installments just because its resolved range happens to span
     * several calendar months, per the business rule that payment
     * schedule stays a separate concept from service coverage), one
     * ServiceCoverage, and one InstallmentCoveragePeriod — spanning the
     * item's own resolved [food_coverage_start, food_coverage_end]
     * (InvoiceIssuanceService::issue()'s $item['food_resolution'],
     * carried onto the persisted InvoiceItem.metadata by
     * InvoiceCalculationService::priceFoodDailyLine()) — never the
     * installment schedule of whatever other Fee happens to share this
     * invoice. Entirely independent of $data['payment_type']/billing_period,
     * which govern only non-Food Fees on the same invoice, if any.
     *
     * Reuses ServiceCoverageService::recordWithBasisPrice() completely
     * unchanged — the exact same call createAutomaticCoverage()'s own
     * Food branch used to make (that branch is now unreachable dead code,
     * kept only as defensive fallback, since $invoiceFees passed to
     * createAutomaticCoverage() above never includes a Food Fee anymore).
     */
    private function createFoodInstallmentAndCoverage(Invoice $invoice, InvoiceItem $item, User $actor): void
    {
        $coverageStart = $item->metadata['food_coverage_start'] ?? null;
        $coverageEnd = $item->metadata['food_coverage_end'] ?? null;
        if (! $coverageStart || ! $coverageEnd || empty($item->metadata['food_tariff_segments'])) {
            throw ValidationException::withMessages(['fees' => "Не удалось определить период покрытия питания для позиции счёта #{$item->id}."]);
        }

        $nextSequence = ((int) $invoice->installments()->max('sequence')) + 1;
        $installment = InvoiceInstallment::create([
            'invoice_id' => $invoice->id,
            'name_ru' => 'Питание',
            'sequence' => $nextSequence,
            'due_date' => $invoice->due_date,
            'amount' => $item->amount,
            'paid_amount' => '0.00',
            'remaining_amount' => $item->amount,
            'status' => bccomp((string) $item->amount, '0.00', 2) <= 0
                ? InvoiceInstallment::STATUS_PAID
                : InvoiceInstallment::STATUS_PENDING,
        ]);

        $basisPrice = FeePrice::query()->lockForUpdate()->findOrFail((int) $item->metadata['food_tariff_segments'][0]['fee_price_id']);
        $coverage = $this->coverage->recordWithBasisPrice($item, $basisPrice, [
            'coverage_start' => $coverageStart,
            'coverage_end' => $coverageEnd,
            'billing_unit' => 'daily',
            'metadata' => [
                'food_calendar_id' => $item->metadata['food_calendar_id'] ?? null,
                'food_calendar_rule_version' => $item->metadata['food_calendar_rule_version'] ?? null,
                'food_billable_day_count' => $item->metadata['food_billable_day_count'] ?? null,
                'food_duration_mode' => $item->metadata['food_duration_mode'] ?? null,
                'food_requested_day_count' => $item->metadata['food_requested_day_count'] ?? null,
                'food_tariff_segments' => $item->metadata['food_tariff_segments'],
            ],
        ], $actor);

        InstallmentCoveragePeriod::create([
            'invoice_installment_id' => $installment->id,
            'service_coverage_id' => $coverage->id,
            'period_start' => $coverageStart,
            'period_end' => $coverageEnd,
            'amount' => $item->amount,
        ]);
    }

    /**
     * Finance V2, Phase 2D corrective pass — same convention as
     * InvoicePaymentService::replay(): a key reused for a genuinely
     * different submission (different student/year/date/payment_type/items)
     * is rejected rather than silently returning an unrelated invoice.
     */
    private function replayInvoice(Invoice $invoice, ?string $hash): Invoice
    {
        if ($hash !== null && ! hash_equals((string) $invoice->idempotency_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ повторного запроса уже использован для другого оформления счёта.']);
        }

        return $invoice;
    }

    /**
     * Corrective pass #3 (HIGH 1 — direct InvoiceIssuanceService
     * idempotency hash, not borrowed from Quick Registration). Covers
     * every field that can change the resulting Invoice/InvoiceItem/
     * InvoiceInstallment/ServiceCoverage graph this method itself
     * produces: student_id, academic_year_id, pricing_date, due_date,
     * payment_type, billing_period, payment_plan_id, discount_type,
     * discount_value, origin, and every item's own canonical dimensions
     * (fee_id, fee_price_id, quantity, grade_group, payment_period,
     * option_type, option_value, size, item, first_last_month) — the
     * exact set StoreInvoiceRequest/QuickStudentRegistrationService's own
     * item shape carries and this method's own calculate()/InvoiceItem
     * creation actually consumes.
     *
     * Deliberately excludes free-text notes ($data['notes']) — a
     * submission differing only in a descriptive comment is still the
     * same financial transaction, consistent with every other Finance V2
     * idempotency hash in this codebase (InvoicePaymentService::record()'s
     * own hash never includes its $notes parameter either).
     *
     * Canonicalized: associative array keys sorted at every level so key-
     * ordering differences never change the hash; numeric-with-decimal
     * strings normalized via bcadd(...,'0',2) so "1500" and "1500.00"
     * never diverge. Items keep their SUBMITTED ORDER, never sorted —
     * verified, not assumed: this method's own $hasDuplicateFeeLines
     * handling (see issue() above) is explicit acknowledgment that two
     * lines sharing the same fee_id is a real, supported, and
     * economically DIFFERENT case from submitting it once (a doubled
     * charge), so line order can never be treated as non-financial here.
     */
    private function canonicalPayloadHash(Student $student, array $data, ?string $origin): string
    {
        $material = [
            'student_id' => $student->id,
            'academic_year_id' => $data['academic_year_id'] ?? null,
            'pricing_date' => $data['pricing_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'payment_type' => $data['payment_type'] ?? 'one_time',
            'billing_period' => $data['billing_period'] ?? null,
            'coverage_start' => $data['coverage_start'] ?? null,
            'coverage_end' => $data['coverage_end'] ?? null,
            'payment_plan_id' => $data['payment_plan_id'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $data['discount_value'] ?? null,
            'origin' => $origin,
            'items' => collect($data['items'])->map(fn (array $item) => collect($item)->only([
                'fee_id', 'fee_price_id', 'quantity', 'grade_group', 'payment_period',
                'option_type', 'option_value', 'size', 'item', 'first_last_month',
                // Food flexible-duration corrective pass: the RAW duration-
                // mode selection must be hashed directly, not just the
                // resolved coverage_start/coverage_end below — for
                // 'teaching_days' mode the caller supplies a COUNT, not an
                // end date, and two different counts are a materially
                // different purchase even if a since-changed calendar
                // could make them resolve to coincidentally similar dates.
                'food_duration_mode', 'food_date', 'food_week_start', 'food_start_date',
                'food_day_count', 'food_month', 'food_end_month', 'food_range_start', 'food_range_end',
            ])->all())->all(),
        ];

        return hash('sha256', json_encode($this->canonicalizeForHash($material)));
    }

    private function canonicalizeForHash(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $canonicalized = collect($value)->map(fn ($v) => $this->canonicalizeForHash($v));

            return $isList ? $canonicalized->values()->all() : $canonicalized->sortKeys()->all();
        }
        // Corrective pass #3 (P2 — scalar-form normalization): a single
        // canonical form regardless of the value's original PHP type or
        // string formatting — "1", 1, and 1.0 all normalize identically,
        // never producing a false "different submission" rejection for
        // what is genuinely the same retry, and never collapsing two
        // actually-different values together (see QuickStudentRegistrationService::
        // canonicalizeForHash()'s own identical logic and docblock).
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $decimal = bcadd((string) $value, '0', 6);

            return bccomp($decimal, (string) (int) $decimal, 6) === 0
                ? (string) (int) $decimal
                : bcadd((string) $value, '0', 2);
        }

        return $value;
    }
}

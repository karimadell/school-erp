<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FeeBillingPeriod;
use App\Models\InstallmentCoveragePeriod;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     */
    public function issue(Student $student, array $data, User $actor, ?string $ip = null, ?string $userAgent = null, ?Closure $subscriptionResolver = null, ?string $origin = null): Invoice
    {
        if ((int) $data['student_id'] !== $student->id) {
            throw ValidationException::withMessages(['student_id' => 'Выбранный ученик не соответствует адресу формы.']);
        }

        return DB::transaction(function () use ($data, $student, $actor, $ip, $userAgent, $subscriptionResolver, $origin) {
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
            $calculation = $this->calculator->calculate($items, $data['discount_type'] ?? null, $data['discount_value'] ?? null, '0', $data['pricing_date'], $year->id);
            $invoiceData = [
                'student_id'=>$student->id, 'academic_year_id'=>$year->id, 'customer_name'=>$student->full_name,
                'currency'=>'EGP', 'subtotal_amount'=>$calculation['subtotal'], 'total_amount'=>$calculation['total_amount'],
                'discount_type'=>$data['discount_type'] ?? null, 'discount_value'=>$data['discount_value'] ?? '0.00',
                'discount_amount'=>$calculation['discount_amount'], 'paid_amount'=>'0.00', 'remaining_amount'=>$calculation['total_amount'],
                'status'=>Invoice::STATUS_UNPAID, 'due_date'=>$data['due_date'],
                'created_by'=>$actor->id,
            ];
            if (Schema::hasColumn('invoices', 'note')) {
                $invoiceData['note'] = $data['notes'] ?? null;
            }
            if ($origin !== null && Schema::hasColumn('invoices', 'origin')) {
                $invoiceData['origin'] = $origin;
            }
            $invoice = new Invoice($invoiceData);
            $invoice->created_at = Carbon::parse($data['pricing_date'])->startOfDay();
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

            foreach ($calculation['line_items'] as $line) {
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
            $invoiceFees = $feesById;

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
            } elseif ($data['payment_type'] === 'calendar') {
                $billingPeriod = $data['billing_period'] ?? null;
                if (! in_array($billingPeriod, FeeBillingPeriod::CALENDAR_PERIODS, true)) {
                    throw ValidationException::withMessages(['billing_period' => 'Укажите период оплаты.']);
                }
                foreach ($invoiceFees as $fee) {
                    if (! $fee->allowsBillingPeriod($billingPeriod)) {
                        $periodLabel = FeeBillingPeriod::PERIOD_LABELS[$billingPeriod] ?? $billingPeriod;
                        throw ValidationException::withMessages(['billing_period' => "Услуга «{$fee->name_ru}» не поддерживает период оплаты «{$periodLabel}»."]);
                    }
                }
                $schedule = $this->plans->generateCalendarSchedule($invoice, $billingPeriod, $data['pricing_date'], $year->end_date->toDateString());

                // Finance V2, Phase 2D item 2: automatic ServiceCoverage for
                // calendar-billed invoices. Scoped to 'monthly' only —
                // ServiceCoverage/TariffAdjustmentService's own billing_unit
                // constraint (monthly/daily only, confirmed by reading both)
                // means quarterly/yearly calendar schedules structurally
                // cannot get coverage without redesigning TariffAdjustmentService
                // itself, explicitly out of scope for this phase. This is an
                // honest, reported scope boundary, not an oversight.
                if ($billingPeriod === FeeBillingPeriod::PERIOD_MONTHLY) {
                    $this->createAutomaticCoverage($schedule, $itemsByFeeId, $invoiceFees, $actor);
                }
            } else {
                $this->plans->generateSingle($invoice, $data['due_date']);
            }

            AuditLog::create(['user_id'=>$actor->id,'action'=>'created','model'=>'Invoice','model_id'=>$invoice->id,'new_values'=>['invoice_number'=>$invoice->invoice_number,'total_amount'=>$invoice->total_amount],'ip'=>$ip,'user_agent'=>$userAgent]);

            return $invoice;
        });
    }

    /**
     * Finance V2, Phase 2D item 2 — automatic ServiceCoverage creation for
     * a monthly calendar-billed invoice. Runs inside issue()'s own
     * DB::transaction() (no second transaction opened here), so an
     * issuance rollback rolls this back too, and a retried submission
     * that hits the M3 idempotency fail-safe never leaves an orphan
     * coverage row (confirmed by the retry regression test).
     *
     * One ServiceCoverage per periodic Fee on the invoice, spanning the
     * FULL generated schedule (first period's start through last period's
     * end) — matching how ServiceCoverage already supports multi-month
     * spans and how TariffAdjustmentService already expects to consume
     * it. One InstallmentCoveragePeriod per (installment, coverage) pair
     * — every Fee sharing this invoice's one schedule sees the same
     * period boundaries per installment (M1: all Fees on one invoice
     * share one billing strategy).
     *
     * Coverage creation is best-effort and must never block real invoice
     * issuance over an audit/reporting gap: ServiceCoverageService::record()
     * is reused verbatim (its existing validation is NOT weakened), and a
     * ValidationException from it — most commonly a Fee whose resolved
     * FeePrice doesn't carry a payment_period dimension matching the
     * billing_unit being requested (see the Phase 2D report for exactly
     * when this happens, e.g. Food priced with a monthly-only tariff when
     * daily coverage was attempted) — is caught and logged, skipping
     * coverage for that one Fee only. The invoice/payment itself is
     * unaffected either way.
     *
     * @param  array<int, array{installment: \App\Models\InvoiceInstallment, period_start: string, period_end: string}>  $schedule
     * @param  array<int, InvoiceItem>  $itemsByFeeId
     * @param  \Illuminate\Support\Collection<int, Fee>  $invoiceFees
     */
    private function createAutomaticCoverage(array $schedule, array $itemsByFeeId, \Illuminate\Support\Collection $invoiceFees, User $actor): void
    {
        if ($schedule === []) {
            return;
        }
        $coverageStart = $schedule[0]['period_start'];
        $coverageEnd = end($schedule)['period_end'];

        foreach ($invoiceFees as $fee) {
            $item = $itemsByFeeId[$fee->id] ?? null;
            if (! $item) {
                continue;
            }
            $billingUnit = $fee->category === Fee::CATEGORY_FOOD ? 'daily' : 'monthly';
            $feePriceId = $item->metadata['fee_price_id'] ?? null;
            if (! $feePriceId) {
                // Nothing to snapshot a coverage tariff against — the
                // resolved price for this line was never traced into
                // metadata (e.g. an item priced through the flat
                // Fee.amount/base_price fallback, with no real FeePrice
                // row at all). Skip, don't guess a tariff.
                continue;
            }

            try {
                $coverage = $this->coverage->record($item, [
                    'fee_price_id' => $feePriceId,
                    'coverage_start' => $coverageStart,
                    'coverage_end' => $coverageEnd,
                    'billing_unit' => $billingUnit,
                ], $actor);
            } catch (ValidationException $exception) {
                Log::warning(
                    "Finance V2 Phase 2D: automatic ServiceCoverage skipped for fee_id={$fee->id} on invoice_item_id={$item->id} — {$exception->getMessage()}",
                );

                continue;
            }

            foreach ($schedule as $period) {
                InstallmentCoveragePeriod::create([
                    'invoice_installment_id' => $period['installment']->id,
                    'service_coverage_id' => $coverage->id,
                    'period_start' => $period['period_start'],
                    'period_end' => $period['period_end'],
                ]);
            }
        }
    }
}

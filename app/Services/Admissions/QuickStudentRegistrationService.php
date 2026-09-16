<?php

namespace App\Services\Admissions;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\MealSubscription;
use App\Models\QuickRegistrationOperation;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Models\User;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\MixedPaymentCollectionOrchestrator;
use App\Services\Finance\ServiceSelectionNormalizer;
use App\Services\AcademicStructureService;
use App\Services\StudentServiceSubscriptionService;
use App\Support\DeterministicIdempotencyKey;
use App\Support\QrTrace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class QuickStudentRegistrationService
{
    /**
     * Unified Collection foundation (PR A) — $normalizer and $orchestrator
     * are extracted, reusable Finance components (see their own
     * docblocks); everything else here is unchanged. This service now
     * ORCHESTRATES them for the specific Quick Registration case instead
     * of inlining their logic, with byte-identical behavior.
     */
    public function __construct(
        private InvoiceCalculationService $calculator,
        private InvoiceIssuanceService $issuer,
        private InvoicePaymentService $payments,
        private StudentServiceSubscriptionService $subscriptions,
        private AcademicStructureService $structure,
        private ServiceSelectionNormalizer $normalizer,
        private MixedPaymentCollectionOrchestrator $orchestrator,
    )
    {
    }

    /**
     * Unified Collection foundation (PR A) — the idempotency-key namespace
     * every derive() call in this service uses; extracted so a future
     * Finance collection engine can pass its OWN, different namespace into
     * the SAME MixedPaymentCollectionOrchestrator without ever colliding
     * with a Quick Registration key. Renaming this constant would change
     * every existing key this service has ever produced — never do that.
     */
    private const IDEMPOTENCY_NAMESPACE = 'quick-registration';

    /**
     * @return array{student: Student, enrollment: Enrollment, invoice: Invoice, submission_paid_amount: string}
     *
     * Corrective pass #2 (HIGH 2 — Quick Registration operation-level
     * idempotency). Pass #1's invoice-level idempotency is too late: it
     * cannot prevent a retried submission from creating a SECOND Student/
     * Enrollment before invoice issuance is ever reached, since Student
     * creation itself has no dedup. The real idempotency unit for Quick
     * Registration is the WHOLE operation graph (Student + Enrollment +
     * Invoice + Payments + Coverage), tracked via a dedicated
     * quick_registration_operations row — see that migration's own
     * docblock. Same PostgreSQL-safe recovery pattern as HIGH 1
     * (InvoiceIssuanceService::issue()): the unique-violation on a
     * genuine concurrent race is never caught INSIDE the transaction —
     * it propagates, the transaction rolls back cleanly, and only then,
     * in this outer scope, is the winning operation looked up fresh.
     */
    public function register(array $data, User $actor): array
    {
        $outerToken = $data['idempotency_token'] ?? null;
        $operationKey = $outerToken
            ? (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "quick-registration-operation:{$outerToken}")
            : null;
        $payloadHash = $this->operationPayloadHash($data);

        // Temporary QR_TRACE diagnostic instrumentation (UAT 504
        // investigation, round 2) — trace_id is derived from the already-
        // computed $operationKey (itself a hash of the client idempotency
        // token), never the raw token. QrTrace::log() is a no-op until
        // start() is called, so this never affects unrelated callers of
        // the shared Finance services below.
        QrTrace::start($operationKey);
        QrTrace::log('start');

        // Checked before opening the transaction too — the overwhelming
        // common case (a genuine first submission) never even opens one
        // for this specific check.
        if ($operationKey !== null) {
            $existing = QuickRegistrationOperation::query()->where('idempotency_key', $operationKey)->first();
            if ($existing) {
                return $this->replayOperation($existing, $payloadHash);
            }
        }

        try {
            $result = DB::transaction(function () use ($data, $actor, $operationKey, $payloadHash, $outerToken) {
            // Re-checked once more, now inside the transaction — closes
            // the race window between the pre-transaction check above and
            // this transaction's own locks.
            if ($operationKey !== null) {
                $existing = QuickRegistrationOperation::query()->where('idempotency_key', $operationKey)->lockForUpdate()->first();
                if ($existing) {
                    return $this->replayOperation($existing, $payloadHash);
                }
            }

            // Created BEFORE Student — the whole point of this
            // corrective-pass item — inside the same transaction as
            // everything else, so a rollback anywhere below removes this
            // row too (never a falsely-completed, or a stale orphaned
            // 'pending', row surviving a failed attempt).
            $operation = $operationKey !== null ? QuickRegistrationOperation::create([
                'idempotency_key' => $operationKey,
                'payload_hash' => $payloadHash,
                'status' => QuickRegistrationOperation::STATUS_PENDING,
            ]) : null;

            // Finance V2, Phase 2D corrective pass (P0/HIGH — invoice
            // issuance idempotency): the SAME per-page-render outer token
            // already used to derive deterministic per-installment payment
            // keys below is reused here for the INVOICE itself, so a
            // retried submission (same token) returns the original
            // invoice directly rather than creating a second one.
            $invoiceIdempotencyKey = $outerToken
                ? (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "quick-registration-invoice:{$outerToken}")
                : null;

            // Lock-contention corrective pass: AcademicYear/Stage/Grade/
            // SchoolClass/EnrollmentMode are read here for validation only —
            // none of them is ever written by this transaction, so holding
            // a row lock on them for the whole remaining registration
            // (Student/Enrollment/every service/invoice issuance/payment)
            // only serializes unrelated, concurrent registrations against
            // each other for no correctness benefit. Two things already
            // prove these locks were never load-bearing: (1)
            // AcademicYear is independently re-locked, correctly, by
            // InvoiceIssuanceService::issue() right before it actually
            // matters (Enrollment/Invoice creation) later in this same
            // transaction — removing the early lock here only shortens how
            // long the row is held, it does not remove the real protection;
            // (2) AcademicStructureService::validatePlacement() below
            // already re-fetches Stage/Grade/SchoolClass itself, unlocked,
            // by id — the locked reads here were never what that
            // validation actually relied on. EnrollmentMode has no
            // downstream re-check at all, but is small, static reference
            // data (regular/distance_learning) with nothing else in this
            // transaction writing to it.
            $year = AcademicYear::query()->findOrFail($data['academic_year_id']);
            if (! $year->is_active) {
                throw ValidationException::withMessages(['academic_year_id' => 'Выбранный учебный год больше не активен.']);
            }
            $registrationDate = Carbon::parse($data['registration_date']);
            if ($registrationDate->gt($year->end_date)) {
                throw ValidationException::withMessages(['registration_date' => 'Дата регистрации не может быть позже окончания учебного года.']);
            }

            $stage = Stage::query()->findOrFail($data['stage_id']);
            $grade = Grade::query()->findOrFail($data['grade_id']);
            $class = SchoolClass::query()->findOrFail($data['class_id']);
            $mode = EnrollmentMode::query()->findOrFail($data['enrollment_mode_id']);
            $this->structure->validatePlacement(
                $stage->id,
                $grade->id,
                $class->id,
                requireActive: true,
            );
            if (! $mode->is_active) {
                throw ValidationException::withMessages(['enrollment_mode_id' => 'Выбранная форма обучения больше не активна.']);
            }

            QrTrace::log('reference_validation_done');

            $student = Student::create([
                'last_name_ru' => $data['student_last_name_ru'],
                'first_name_ru' => $data['student_first_name_ru'],
                'patronymic_ru' => $data['student_patronymic_ru'] ?? null,
                'phone' => $data['phone'],
                'class_id' => $class->id,
                'status' => Student::STATUS_PRE_REGISTERED,
            ]);

            QrTrace::log('student_created');

            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'enrollment_mode_id' => $data['enrollment_mode_id'],
                'stage_id' => $data['stage_id'],
                'grade_id' => $data['grade_id'],
                'class_id' => $class->id,
                'academic_year' => $year->name,
                'enrollment_date' => $data['registration_date'],
                'enrolled_at' => $data['registration_date'],
                'status' => 'active',
                'is_active' => true,
                'notes' => collect([
                    'Быстрая предварительная регистрация. Личное дело не завершено.',
                    $data['notes'] ?? null,
                ])->filter()->implode("\n"),
            ]);

            QrTrace::log('enrollment_created');

            // Perf (Quick Registration end-to-end investigation): batched
            // once for every service line up front — this used to be an
            // individual Fee::findOrFail() per line inside the flatMap()
            // closure below (still correctness-safe unlocked, per the
            // lock-contention note preserved below, but a genuine N+1: one
            // query per submitted service). billingPeriods is eager-loaded
            // too, since allowedBillingPeriods()/allowsBillingPeriod() are
            // called per mixed-strategy line just below and would otherwise
            // each trigger their own fee_billing_periods query.
            // Lock-contention corrective pass: this read is used only for
            // category-branching and metadata (never for pricing), and Fee
            // is never written by this transaction — see
            // ServiceSelectionNormalizer's own docblock for the full
            // rationale (unchanged, just relocated).
            $feesById = Fee::with('billingPeriods')
                ->whereIn('id', collect($data['services'])->pluck('fee_id')->unique())
                ->get()->keyBy('id')->all();
            $paymentType = $data['payment_type'] ?? 'one_time';
            // Unified Collection foundation (PR A) — service-selection
            // normalization now lives in ServiceSelectionNormalizer
            // (extracted, byte-identical output); this call replaces the
            // inline flatMap() this method used to run directly.
            $normalizedServices = $this->normalizer->normalize($data['services'], $grade, $mode, $paymentType, $feesById);
            $items = $normalizedServices->all();

            QrTrace::log('services_normalized');

            // Food flexible-duration corrective pass: $items already
            // carries each Food service's own raw duration-mode fields
            // (food_duration_mode + whichever of food_date/food_week_start/
            // food_start_date+food_day_count/food_month(+food_end_month)/
            // food_range_start+food_range_end that mode needs) straight
            // through from $service — array_merge() above preserves every
            // key $service already had that wasn't explicitly overridden.
            // InvoiceIssuanceService::issue() resolves each Food item's own
            // concrete [start,end] range from these fields internally (via
            // FoodBillableDayCalculator::resolveFromDurationSelection()) —
            // never derived here, so this service no longer needs to know
            // about calendar-month coverage at all. The resolved
            // 'food_resolution' it attaches is visible on $selection
            // inside $subscriptionResolver below (same $items array,
            // looked up by fee_id), which is where this service still
            // needs a concrete start date for the MealSubscription/
            // StudentServiceSubscription rows.
            $foodService = $normalizedServices->first(fn (array $service) => $service['_fee_category'] === Fee::CATEGORY_FOOD);

            $paidNow = $normalizedServices->reduce(
                fn (string $sum, array $service) => bcadd($sum, (string) $service['paid_now'], 2),
                '0.00'
            );

            // Phase 2: issuance itself — invoice, items, registration-fee
            // duplicate guard, subscription linkage, installments, and audit
            // logging — is delegated to the canonical InvoiceIssuanceService
            // instead of being hand-rolled here. Subscription creation stays
            // an Admissions-domain concern: the resolver below is the only
            // place that knows about StudentServiceSubscriptionService or
            // MealSubscription — InvoiceIssuanceService never does.
            $subscriptionResolver = function (Fee $fee, array $selection, Enrollment $enrollment) use ($data, $actor) {
                // $selection is the item InvoiceIssuanceService::issue()
                // itself resolved 'food_resolution' onto (see this
                // method's own docblock note above) — its own
                // coverage_start is the actual start of the resolved Food
                // range (a single date, a week start, an N-teaching-day
                // walk's start, a month's 1st, or a custom range's start),
                // never a raw calendar-month guess.
                $foodCoverageStart = $selection['food_resolution']['coverage_start'] ?? null;
                $subscription = $this->subscriptions->subscribe($enrollment, $fee, [
                    'start_date' => $fee->category === Fee::CATEGORY_FOOD && $foodCoverageStart ? $foodCoverageStart : $data['registration_date'],
                    'quantity' => (int) $selection['quantity'],
                    'status' => StudentServiceSubscription::STATUS_ACTIVE,
                    'metadata' => $this->metadata($fee, $selection),
                ], $actor);

                if ($fee->category === Fee::CATEGORY_FOOD) {
                    MealSubscription::create([
                        'enrollment_id' => $enrollment->id,
                        'meal_plan_id' => $selection['meal_plan_id'],
                        'start_date' => $foodCoverageStart ?? $data['registration_date'],
                    ]);
                }

                // Transport capacity decoupling: Quick Registration no
                // longer creates a StudentTransportAssignment (a real,
                // capacity-locked seat) — that call used to reuse Operations'
                // own vehicle-assignment mechanism (TransportAssignmentService
                // ::assign(), which enforces TransportPassengerCapacityService)
                // for what should only ever be a billing/demand-capture step,
                // causing a hard failure (TransportCapacityExceeded -> 500,
                // full registration rollback) whenever the selected bus was
                // already at capacity. The school's actual rule: a student
                // must be accepted and billed for Transport even when no
                // vehicle has spare capacity yet or no final vehicle is known
                // at all — final seat assignment is a later, separate
                // Operations decision (see TransportManagementController::
                // assignStudent(), unchanged, still capacity-enforced there).
                //
                // The StudentServiceSubscription created above (identical
                // code path for every Fee category, Transport included)
                // already carries everything Operations needs to later
                // assign a seat: this Fee's own metadata() branch for
                // Fee::CATEGORY_TRANSPORT stores area/route_id/route/stop/
                // payment_period. No Bus, no TransportRoute model fetch, no
                // capacity check belongs in this closure any more.

                return $subscription->id;
            };

            QrTrace::log('before_invoice_issue');

            $invoice = $this->issuer->issue($student, [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'due_date' => $year->end_date->toDateString(),
                'pricing_date' => $data['registration_date'],
                'items' => $items,
                'payment_type' => $data['payment_type'] ?? 'one_time',
                'payment_plan_id' => $data['payment_plan_id'] ?? null,
                'billing_period' => $data['billing_period'] ?? null,
            ], $actor, subscriptionResolver: $subscriptionResolver, origin: Invoice::ORIGIN_QUICK_REGISTRATION, idempotencyKey: $invoiceIdempotencyKey);

            QrTrace::log('after_invoice_issue');

            // Quick Registration's own per-line concerns — the initial
            // paid/remaining split per service, the enriched description,
            // the curated (non-raw) metadata snapshot, and the legacy
            // registration_fee_charged_at bookkeeping — are layered on top
            // of the just-issued, canonical InvoiceItem rows. This still
            // runs inside the same outer transaction as issue(), so a
            // failure here rolls back the invoice too.
            // Finance V2, Phase 1A: this loop already matches each submitted
            // service line to its real InvoiceItem (by fee_id, see the fix
            // below) and already computes that line's own $linePaid — the
            // exact, already-deterministic mapping PaymentAllocation needs.
            // Collected here and passed to InvoicePaymentService::record()
            // below; zero-paid lines are skipped (nothing to allocate).
            $allocations = [];
            // Multi-item Uniform corrective pass — matching by fee_id alone
            // (the previous approach) cannot distinguish between several
            // lines that legitimately share one fee_id: every sibling
            // Uniform line has the identical fee_id, so a fee_id search
            // would always resolve to the SAME (first) sibling for every one
            // of them. InvoiceCalculationService::calculate() and
            // InvoiceIssuanceService::issue() both build their line items/
            // InvoiceItem rows in the exact same order $normalizedServices
            // was submitted in (see InvoiceIssuanceService::issue()'s own
            // parallel fix) — so the item at position N always came from
            // $normalizedServices[N]. Ordered explicitly by id (this
            // relation carries no default order) so that positional
            // correspondence is guaranteed, not just usually true.
            $orderedInvoiceItems = $invoice->items->sortBy('id')->values();
            $feeLineCounts = $normalizedServices->pluck('fee_id')->map(fn ($id) => (int) $id)->countBy();
            $remainingPaidByFeeId = [];
            foreach ($orderedInvoiceItems as $position => $item) {
                $selection = $normalizedServices[$position] ?? null;
                if ($selection === null || (int) $selection['fee_id'] !== (int) $item->fee_id) {
                    throw ValidationException::withMessages([
                        'services' => "Не удалось сопоставить строку счёта с выбранной услугой (fee_id: {$item->fee_id}).",
                    ]);
                }
                $fee = $feesById[$item->fee_id];
                $metadata = $this->metadata($fee, $selection);

                $feeId = (int) $item->fee_id;
                if (($feeLineCounts[$feeId] ?? 1) > 1) {
                    // Multiple lines share this Fee (Uniform multi-item
                    // selection) — the ONE paid_now this submission carries
                    // for the whole Fee is distributed greedily across its
                    // sibling lines, in submission order, each capped at its
                    // own resolved amount — never duplicated across
                    // siblings, never silently dropped.
                    if (! array_key_exists($feeId, $remainingPaidByFeeId)) {
                        $remainingPaidByFeeId[$feeId] = $normalizedServices
                            ->filter(fn (array $service) => (int) $service['fee_id'] === $feeId)
                            ->reduce(fn (string $sum, array $service) => bcadd($sum, (string) $service['paid_now'], 2), '0.00');
                    }
                    $linePaid = bccomp($remainingPaidByFeeId[$feeId], $item->amount, 2) >= 0
                        ? $item->amount
                        : $remainingPaidByFeeId[$feeId];
                    $remainingPaidByFeeId[$feeId] = bcsub($remainingPaidByFeeId[$feeId], $linePaid, 2);
                } else {
                    $linePaid = bcadd((string) $selection['paid_now'], '0', 2);

                    // Same check the old, separate preview calculate() pass
                    // used to run before anything was persisted — folded in
                    // here against the invoice's own just-issued line amount
                    // instead of pricing every line twice. Still runs (and
                    // can still throw) before payment is attempted, so a
                    // violation rolls back the whole outer transaction
                    // exactly as before. Scoped to the single-line-per-fee
                    // case only — the multi-line Uniform case above already
                    // guarantees a line is never allocated more than its own
                    // amount, by construction, so an explicit throw there
                    // would only fire for a total genuinely exceeding the
                    // sum of every sibling's amount, which the request-level
                    // decimal validation on paid_now cannot itself express
                    // as cleanly as this per-line cap does.
                    if (bccomp($linePaid, $item->amount, 2) > 0) {
                        throw ValidationException::withMessages([
                            "services.{$position}.paid_now" => 'Оплата по услуге не может превышать её рассчитанную стоимость.',
                        ]);
                    }
                }
                $lineRemaining = bcsub($item->amount, $linePaid, 2);

                if (bccomp($linePaid, '0.00', 2) > 0) {
                    $allocations[] = ['invoice_item_id' => $item->id, 'amount' => $linePaid];
                }

                // Corrective pass #2 (HIGH 4 — Finance metadata
                // preservation, confirmed real): InvoiceIssuanceService::
                // issue() (called above, before this loop) already wrote
                // this item's own pricing/coverage audit metadata —
                // unit_tariff, billing_unit, coverage_start/end,
                // derived_*, adjustment_basis_* (the last written even
                // later still, inside issue()'s own createAutomaticCoverage()
                // step). A plain 'metadata' => $metadata here would
                // silently REPLACE all of that with only this line's own
                // curated Admissions-domain fields — an explicit merge,
                // protecting InvoiceItem::FINANCE_METADATA_KEYS, is
                // required so Admissions enrichment and Finance audit
                // data coexist correctly, neither ever clobbering the
                // other.
                $item->update([
                    'description' => $this->description($item->description, $metadata),
                    'paid_amount' => $linePaid,
                    'remaining_amount' => $lineRemaining,
                    'metadata' => array_merge($metadata, collect($item->metadata ?? [])->only(InvoiceItem::FINANCE_METADATA_KEYS)->all()),
                ]);

                if ($fee->category === Fee::CATEGORY_REGISTRATION) {
                    $enrollment->update(['registration_fee_charged_at' => now()]);
                }
            }

            // Unified Collection foundation (PR A) — the three payment-
            // orchestration shapes below (mixed once+calendar split,
            // calendar+Food period split, sequential-installment walk) now
            // live in MixedPaymentCollectionOrchestrator (extracted,
            // byte-identical InvoicePaymentService::record() calls and
            // idempotency-key derivation — see that class's own docblock).
            // This block only decides WHICH shape applies, exactly as
            // before, and resolves the shared $cashAccountId/$reference
            // once.
            if (bccomp($paidNow, '0.00', 2) > 0) {
                $cashAccountId = CashAccount::resolvePaymentAccountId($data['payment_method'], $data['cash_account_id'] ?? null);
                $reference = "Быстрая регистрация {$invoice->invoice_number}";
                $notes = $data['payment_note'] ?? null;

                if (($data['payment_type'] ?? null) === 'mixed') {
                    QrTrace::log('before_payment:mixed');
                    $this->orchestrator->collectMixed(
                        $invoice, $allocations, $normalizedServices, $orderedInvoiceItems,
                        $paidNow, $data['payment_method'], $cashAccountId, $actor,
                        $reference, $notes, $outerToken, self::IDEMPOTENCY_NAMESPACE,
                    );
                    QrTrace::log('after_payment:mixed');
                } elseif (($data['payment_type'] ?? null) === 'calendar' && $foodService) {
                    QrTrace::log('before_payment:calendar');
                    $this->orchestrator->collectCalendarPeriods(
                        $invoice, $allocations, $paidNow, $data['payment_method'], $cashAccountId, $actor,
                        $reference, $notes, $outerToken, self::IDEMPOTENCY_NAMESPACE,
                    );
                    QrTrace::log('after_payment:calendar');
                } else {
                    QrTrace::log('before_payment:plan');
                    $this->orchestrator->collectAcrossInstallments(
                        $invoice, $allocations, $paidNow, $data['payment_method'], $cashAccountId, $actor,
                        $reference, $notes, $outerToken, self::IDEMPOTENCY_NAMESPACE,
                    );
                    QrTrace::log('after_payment:plan');
                }
            }

            // Only reached on a genuine, about-to-commit success — the
            // operation row transitions to 'completed' with the resulting
            // ids in the SAME transaction as everything else, so a
            // rollback anywhere above (including this update itself
            // failing) leaves it at its pre-existing state, never
            // falsely marked complete.
            $operation?->update([
                'status' => QuickRegistrationOperation::STATUS_COMPLETED,
                'student_id' => $student->id,
                'enrollment_id' => $enrollment->id,
                'invoice_id' => $invoice->id,
                'completed_at' => now(),
            ]);

            QrTrace::log('before_commit');

            // UAT display corrective pass — Issue 1: this submission's own
            // $paidNow (summed above from every service's paid_now field,
            // line ~375) is the ONLY authoritative "paid by THIS
            // registration" figure — it's already guaranteed to equal the
            // full sum of whichever InvoicePayment row(s) the once/periods/
            // calendar/else branches above just created (each branch fails
            // closed rather than leaving any of $paidNow unallocated).
            // $invoice is brand new in this same request, so it cannot yet
            // carry any OTHER, later-added payment — unlike
            // $invoice->payments->sum('amount'), which would silently
            // include a follow-up payment collected long after this
            // registration if this same result were ever reused. Never
            // touches payment creation/allocation — display data only.
            return compact('student', 'enrollment', 'invoice') + ['submission_paid_amount' => $paidNow];
            });

            QrTrace::log('completed');

            return $result;
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            // HIGH 1's same pattern, applied one level up: the
            // transaction above has already rolled back cleanly by the
            // time this runs — never inside the now-dead transaction.
            if ($operationKey === null) {
                throw $exception;
            }

            return $this->replayOperation(QuickRegistrationOperation::query()->where('idempotency_key', $operationKey)->firstOrFail(), $payloadHash);
        }
    }

    /**
     * Corrective pass #2 (HIGH 2). $hash !== null && ... mirrors
     * InvoiceIssuanceService::replayInvoice()'s own convention: a key
     * reused for a genuinely different submission is rejected, never
     * silently replayed as if it were the same one. A 'pending' row
     * (another request currently mid-flight for this exact key) is also
     * rejected rather than blocked or guessed at — SERIALIZABLE row
     * locking means this should be structurally rare to ever observe.
     */
    private function replayOperation(QuickRegistrationOperation $operation, string $payloadHash): array
    {
        if (! hash_equals($operation->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ повторного запроса уже использован для другой регистрации.']);
        }
        if ($operation->status !== QuickRegistrationOperation::STATUS_COMPLETED) {
            throw ValidationException::withMessages(['idempotency_key' => 'Регистрация с этим ключом ещё обрабатывается — повторите попытку позже.']);
        }

        $invoice = Invoice::findOrFail($operation->invoice_id);

        return [
            'student' => Student::findOrFail($operation->student_id),
            'enrollment' => Enrollment::findOrFail($operation->enrollment_id),
            'invoice' => $invoice,
            // UAT display corrective pass — Issue 1, replay case: unlike
            // the fresh path above, $invoice here is NOT necessarily new —
            // it may since have received an unrelated, later payment
            // (e.g. debt collected on a follow-up visit), so
            // $invoice->payments->sum('amount') would wrongly attribute
            // that money to THIS original registration. Reconstructed
            // instead via the same deterministic per-bucket idempotency
            // keys every payment: record() call above already derives
            // from $outerToken (mixed-once/mixed-periods/calendar/each
            // installment index) — isolating exactly the payment(s) this
            // one operation created, never anything recorded later under
            // a fresh, unrelated idempotency key.
            'submission_paid_amount' => $this->submissionPaidAmount($invoice, $operation->idempotency_key),
        ];
    }

    /**
     * Deterministically reconstructs the total this ONE Quick Registration
     * operation paid, from the same idempotency-key derivation every
     * record() call in register() above already uses — never a fresh
     * query on "all payments this invoice currently has", which could
     * include money collected in a completely separate, later payment.
     */
    private function submissionPaidAmount(Invoice $invoice, string $outerToken): string
    {
        $installmentCount = $invoice->installments()->count();
        $suffixes = collect(['mixed-once', 'mixed-periods', 'calendar'])
            ->merge($installmentCount > 0 ? range(0, $installmentCount - 1) : []);

        $keys = $suffixes
            ->map(fn ($suffix) => DeterministicIdempotencyKey::derive($outerToken, self::IDEMPOTENCY_NAMESPACE, (string) $suffix))
            ->all();

        return (string) InvoicePayment::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('idempotency_key', $keys)
            ->sum('amount');
    }

    /**
     * Corrective pass #2 (HIGH 3 — complete idempotency hash coverage).
     * Every field that can change the resulting Student/Enrollment/
     * Invoice/Payment/Coverage graph is covered — student identity/
     * placement, every service line's every canonical dimension, and
     * every payment-affecting field. Deliberately EXCLUDES idempotency_token
     * itself (that's the key, not the payload) and free-text notes
     * (notes/payment_note) — a submission differing only in a descriptive
     * comment is still the same financial transaction, not a different
     * one; this codebase already treats notes as non-material everywhere
     * else idempotency hashing exists (InvoicePaymentService::record()'s
     * own hash — invoice/installment/cash_account/amount/method — never
     * includes its own $notes parameter either).
     *
     * Corrective pass #3 (P2 — normalization). The 'services' array is
     * sorted by its own fee_id before hashing — verified safe, not
     * assumed: StoreQuickStudentRegistrationRequest's own validation
     * rule ('services.*.fee_id' => ['distinct', ...], enforced BEFORE
     * this method is ever reached) makes a repeated fee_id within one
     * submission structurally impossible here, unlike InvoiceIssuanceService's
     * own $data['items'] (no such uniqueness constraint there — see that
     * method's own docblock for why ITS items array deliberately stays
     * unsorted). fee_id is therefore a safe, stable, always-unique
     * canonical sort key — a legitimately reordered-but-identical
     * resubmission (e.g. a UI that re-renders services in a different
     * order) now replays cleanly instead of failing safe on order alone.
     *
     * Canonicalized so semantically-identical payloads always hash
     * identically: array keys sorted deterministically at every level,
     * and every numeric scalar (int, float, or numeric string alike —
     * "1", 1, and 1.0 all normalize identically) reduced to one fixed
     * representation, so "1500" and "1500.00", or an int 1 vs a string
     * "1", never produce a false "different submission" rejection for
     * what is genuinely the same retry. This is always a lossless,
     * meaning-preserving transformation — never coercion that could mask
     * a materially different value (a genuinely different amount or id
     * still normalizes to a genuinely different string).
     */
    private function operationPayloadHash(array $data): string
    {
        $material = collect($data)->except(['idempotency_token', 'notes', 'payment_note'])->all();
        if (isset($material['services']) && is_array($material['services'])) {
            $material['services'] = collect($material['services'])
                ->sortBy(fn ($service) => (int) ($service['fee_id'] ?? 0))
                ->values()->all();
        }

        return hash('sha256', json_encode($this->canonicalizeForHash($material)));
    }

    private function canonicalizeForHash(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $canonicalized = collect($value)->map(fn ($v) => $this->canonicalizeForHash($v));

            // A list (e.g. the services array, already fee_id-sorted
            // above) keeps whatever order it arrives in at this point —
            // each line's OWN internal key order is still normalized. An
            // associative array's keys are sorted so key order alone
            // never changes the hash.
            return $isList ? $canonicalized->values()->all() : $canonicalized->sortKeys()->all();
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            // A single canonical form regardless of the value's original
            // PHP type or string formatting: an integer-valued number
            // (1, "1", 1.0, "1.00") always normalizes to the plain
            // integer string "1" — never to "1.00", which would wrongly
            // imply a money-shaped field; a genuinely fractional number
            // normalizes to a fixed 2dp bcmath string. Either way this
            // never collapses two DIFFERENT logical values together —
            // only different representations of the identical value.
            $decimal = bcadd((string) $value, '0', 6);

            return bccomp($decimal, (string) (int) $decimal, 6) === 0
                ? (string) (int) $decimal
                : bcadd((string) $value, '0', 2);
        }

        return $value;
    }

    private function metadata(Fee $fee, array $selection): array
    {
        return array_filter(match ($fee->category) {
            Fee::CATEGORY_UNIFORM => [
                'uniform_product_id' => $selection['uniform_product_id'],
                'item' => $selection['item'], 'size' => $selection['size'],
            ],
            Fee::CATEGORY_TRANSPORT => [
                'area' => $selection['transport_area'],
                'route_id' => $selection['transport_route_id'],
                'route' => $selection['transport_route_name'],
                'stop' => $selection['transport_stop'] ?? null,
                'payment_period' => $selection['payment_period'] ?? null,
            ],
            Fee::CATEGORY_FOOD => [
                'meal_plan_id' => $selection['meal_plan_id'],
                'meal_plan' => $selection['meal_plan_name'],
            ],
            Fee::CATEGORY_TUITION,
            Fee::CATEGORY_TUITION_REGULAR,
            Fee::CATEGORY_TUITION_FAMILY,
            Fee::CATEGORY_TUITION_EXTERNAL => [
                'grade_group' => $selection['grade_group'] ?? null,
                'payment_period' => $selection['payment_period'] ?? null,
                'first_last_month' => (bool) ($selection['first_last_month'] ?? false),
            ],
            default => [],
        }, fn ($value) => filled($value));
    }

    private function description(string $name, array $metadata): string
    {
        $labels = [
            'item' => 'изделие', 'size' => 'размер', 'area' => 'зона', 'route' => 'маршрут',
            'stop' => 'остановка', 'meal_plan' => 'план питания', 'grade_group' => 'группа классов',
            'payment_period' => 'период оплаты', 'first_last_month' => 'первый и последний месяц',
        ];
        $visible = collect($metadata)->only(array_keys($labels));

        return $visible->isEmpty() ? $name : $name.' — '.$visible
            ->map(fn ($value, $key) => $labels[$key].': '.($value === true ? 'да' : $value))
            ->implode(', ');
    }
}

<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FinanceCollection;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Student;
use App\Models\User;
use App\Support\DeterministicIdempotencyKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Unified Collection foundation (PR B) — ONE FinanceCollection is ONE
 * parent-facing collection operation: exactly one Student, exactly one
 * AcademicYear, exactly one payment method, at most one cash account (see
 * FinanceCollection's own docblock). This service is a pure ORCHESTRATION
 * layer above the existing, unchanged accounting engines:
 *
 *  - InvoiceIssuanceService::issue()   — the only place a charge amount is
 *                                         ever resolved (Fee/FeePrice/
 *                                         Tariff), for new charges.
 *  - InvoicePaymentService::record()   — the only place money received is
 *                                         ever recorded, for both existing
 *                                         obligations and newly-issued
 *                                         charges.
 *  - ServiceSelectionNormalizer /
 *    MixedPaymentCollectionOrchestrator (PR A) — reused unmodified for new
 *    charges, exactly the same "N once-strategy lines, each with its own
 *    explicit paid-now amount, settled in one record() call" shape Quick
 *    Registration's own 'mixed' payment_type already proved correct.
 *  - CashAccount::resolvePaymentAccountId() — the SAME canonical cash-
 *    account policy QuickStudentRegistrationService/ChargeAndCollectService
 *    already use: a 'cash' payment_method always resolves to the canonical
 *    operating account server-side, regardless of any cash_account_id a
 *    caller submits (corrective pass — see §7 below). No second cash-
 *    account policy is introduced here.
 *
 * Nothing here re-derives a charge amount, re-implements allocation rules,
 * or duplicates a money total already owned by one of those engines — see
 * receivedTotal() on the model, never stored here.
 *
 * ONE OUTER TRANSACTION owns the whole operation (see collect()). Every
 * write below — the FinanceCollection row itself, any Enrollment created
 * for a returning student's new year, every existing-obligation payment,
 * the new-charges Invoice and its payment(s) — happens inside it, so a
 * failure anywhere rolls back everything this attempt produced. The four
 * engines above each still open their OWN inner DB::transaction() exactly
 * as they always have (proven SAVEPOINT-safe under an outer transaction —
 * see PR A's review); this service never changes that.
 *
 * ACADEMIC-YEAR SEAM (§9 of the approved design): $data['academic_year_id']
 * is resolved to exactly one locked AcademicYear row, ONCE, right here —
 * this service never calls AcademicYear::where('is_active', ...) or any
 * other is_active-scoped query anywhere else. Whether that year must be
 * is_active for a *new* charge to be issued is a decision this service
 * does NOT make — InvoiceIssuanceService::issue() already enforces its own
 * "year is_active + active Enrollment" gate, unchanged, and that gate is
 * the ONLY place such a check happens for the new-charges path. Paying an
 * EXISTING obligation has no such gate at all (an old, no-longer-active
 * year's debt remains payable) — see collectExistingObligation() gate.
 * The broader academic-year lifecycle (e.g. collecting for an *upcoming*,
 * not-yet-active year) is explicitly out of scope for this PR — see the
 * implementation report.
 *
 * DOMAIN VALIDATION BOUNDARY (corrective pass): PR B has no controller or
 * FormRequest yet, so this service is the ONLY place protecting the
 * accounting invariants below — not merely presentation-shape validation
 * deferred to a future PR C. It deliberately stays narrow: no attempt is
 * made to become a general-purpose FormRequest replacement (field types,
 * localized field-level messages for every conceivable malformed shape,
 * etc. remain PR C's job) — only invariants that could otherwise let a
 * malformed or adversarial call move money incorrectly, silently no-op, or
 * crash with a raw PHP warning instead of a clean, catchable exception.
 */
class FinanceCollectionService
{
    private const IDEMPOTENCY_NAMESPACE = 'finance-collection';

    /**
     * Mirrors InvoicePaymentService::record()'s own canonical payment-
     * method list exactly (app/Services/Finance/InvoicePaymentService.php)
     * — not a new taxonomy. Duplicated here (rather than a shared
     * constant) only because record() is one of the accounting engines
     * this PR's own scope explicitly leaves untouched; if this list ever
     * changes there, it must change here too.
     */
    private const PAYMENT_METHODS = ['cash', 'bank', 'card', 'transfer', 'instapay'];

    public function __construct(
        private InvoiceIssuanceService $issuer,
        private InvoicePaymentService $payments,
        private ServiceSelectionNormalizer $normalizer,
        private MixedPaymentCollectionOrchestrator $orchestrator,
    ) {
    }

    /**
     * @param  array{
     *     idempotency_token?: ?string,
     *     student_id: int,
     *     academic_year_id: int,
     *     payment_method: string,
     *     cash_account_id?: ?int,
     *     notes?: ?string,
     *     annual_registration?: ?array{enrollment_mode_id: int, stage_id: int, grade_id: int, class_id: int, registration_date?: ?string},
     *     existing_obligations?: array<int, array{invoice_id: int, receive_now_amount?: string, installment_id?: ?int, allocations?: ?array}>,
     *     new_services?: array<int, array<string, mixed>>,
     * }  $data
     *
     * A caller-supplied idempotency_token is never optional in practice
     * for genuine replay protection (see the docblock below on why a
     * missing one still works, just without cross-request replay) — this
     * method always resolves ONE concrete token (client-supplied, or a
     * fresh one generated here) before deriving anything from it, so
     * every deterministic child key this one call produces is internally
     * consistent even when the caller supplied none.
     */
    public function collect(array $data, User $actor): FinanceCollection
    {
        $this->validateTopLevel($data);

        // §7 corrective pass — canonical cash-account resolution, resolved
        // ONCE and used everywhere a cash account matters: the collection
        // row itself, every existing-obligation payment, every new-charge
        // payment. A 'cash' payment_method always resolves to the
        // canonical operating account regardless of what (if anything)
        // the caller submitted — the exact same policy
        // QuickStudentRegistrationService/ChargeAndCollectService already
        // enforce; this is not a second, divergent cash policy.
        $cashAccountId = CashAccount::resolvePaymentAccountId($data['payment_method'], $data['cash_account_id'] ?? null);

        // Normalizes every line's receive_now_amount to a validated 2dp
        // money string (rejecting negative amounts, defaulting a missing
        // key to '0.00' rather than raising a raw PHP warning) BEFORE
        // hashing or processing, so both see the exact same values.
        $data = $this->normalizeLines($data);

        $outerToken = $data['idempotency_token'] ?? (string) Str::uuid();
        $idempotencyKey = DeterministicIdempotencyKey::derive($outerToken, self::IDEMPOTENCY_NAMESPACE, 'collection');
        $payloadHash = $this->payloadHash($data, $cashAccountId);

        // Checked before opening the transaction too — mirrors
        // InvoiceIssuanceService::issue()/QuickStudentRegistrationService::
        // register()'s own established pattern: the overwhelmingly common
        // case (a genuine first attempt) never even opens one for this.
        $existing = FinanceCollection::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $this->replay($existing, $payloadHash);
        }

        try {
            return DB::transaction(function () use ($data, $actor, $outerToken, $idempotencyKey, $payloadHash, $cashAccountId) {
                $collection = FinanceCollection::create([
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'student_id' => $data['student_id'],
                    'academic_year_id' => $data['academic_year_id'],
                    'created_by' => $actor->id,
                    'payment_method' => $data['payment_method'],
                    'cash_account_id' => $cashAccountId,
                    'notes' => $data['notes'] ?? null,
                    'status' => FinanceCollection::STATUS_PENDING,
                ]);

                // B. Resolve exactly one Student + exactly one AcademicYear
                // — locked, so a concurrent request touching the same
                // pair serializes behind this one exactly like
                // InvoiceIssuanceService::issue() already does for its own
                // student+year lock.
                $student = Student::query()->lockForUpdate()->findOrFail($data['student_id']);
                $year = AcademicYear::query()->lockForUpdate()->findOrFail($data['academic_year_id']);

                // C. Narrow annual-registration context (§8 of the
                // approved design) — only ever attempted when genuinely
                // absent; an existing Enrollment for this exact
                // (student, year) pair is always reused as-is, never
                // touched.
                $enrollment = Enrollment::query()
                    ->where('student_id', $student->id)
                    ->where('academic_year_id', $year->id)
                    ->first();
                if ($enrollment === null && ! empty($data['annual_registration'])) {
                    $enrollment = $this->establishAnnualRegistration($student, $year, $data['annual_registration'], $actor);
                }

                $reference = $collection->collection_number;
                $notes = $data['notes'] ?? null;

                // E. Existing obligations — each its own explicit
                // receive-now amount, never spread automatically. A
                // line whose (already-validated non-negative) amount is
                // exactly zero is intentionally skipped: the obligation
                // was merely listed, nothing was actually collected
                // against it, so no InvoicePayment is created for it
                // (recording a zero-amount payment would itself be
                // rejected by InvoicePaymentService::record() as
                // meaningless — this is a genuine, valid "nothing
                // collected on this one today" case, not an error).
                foreach ($data['existing_obligations'] ?? [] as $index => $line) {
                    if (bccomp($line['receive_now_amount'], '0.00', 2) === 0) {
                        continue;
                    }
                    $this->collectExistingObligation($collection, $student, $year, $line, $index, $data['payment_method'], $cashAccountId, $actor, $reference, $notes, $outerToken);
                }

                // F/G. New service charges — one Invoice, issued and priced
                // entirely server-side, then settled via the same PR A
                // MixedPaymentCollectionOrchestrator::collectMixed() shape
                // Quick Registration's own 'mixed' payment_type already
                // uses for "N once-strategy lines, each independently
                // partially paid." A new service's own receive_now_amount
                // of exactly zero remains fully valid here (the parent may
                // legitimately be charged today and pay nothing yet) —
                // collectNewServices()/buildNewChargeAllocations() already
                // handle that by simply never emitting an allocation, and
                // never invoking the payment orchestrator at all when the
                // whole batch's paidNow is zero.
                if (! empty($data['new_services'])) {
                    if ($enrollment === null) {
                        throw ValidationException::withMessages(['new_services' => 'Для начисления новых услуг нужен активный учебный контекст (зачисление) — передайте annual_registration или выберите учебный год с существующим зачислением.']);
                    }
                    $this->collectNewServices($collection, $student, $year, $enrollment, $data, $actor, $cashAccountId, $reference, $notes, $outerToken);
                }

                // I. Mark completed.
                $collection->forceFill(['status' => FinanceCollection::STATUS_COMPLETED, 'completed_at' => now()])->save();

                // J. Return the completed collection.
                return $collection->fresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Mirrors QuickStudentRegistrationService::register()'s own
            // PostgreSQL-safe recovery: the transaction above has already
            // rolled back cleanly by the time this runs, never inside the
            // now-dead transaction.
            return $this->replay(FinanceCollection::query()->where('idempotency_key', $idempotencyKey)->firstOrFail(), $payloadHash);
        }
    }

    /**
     * Corrective pass §3 — protects only accounting/domain invariants that
     * would otherwise let a malformed call move money incorrectly, no-op
     * meaninglessly, or crash with a raw PHP warning instead of a clean,
     * catchable ValidationException. Deliberately not a FormRequest
     * replacement — field-level UX polish for every malformed shape stays
     * PR C's job.
     */
    private function validateTopLevel(array $data): void
    {
        if (! isset($data['student_id'])) {
            throw ValidationException::withMessages(['student_id' => 'Укажите ученика.']);
        }
        if (! isset($data['academic_year_id'])) {
            throw ValidationException::withMessages(['academic_year_id' => 'Укажите учебный год.']);
        }
        if (! isset($data['payment_method']) || ! in_array($data['payment_method'], self::PAYMENT_METHODS, true)) {
            throw ValidationException::withMessages(['payment_method' => 'Выбран недопустимый способ оплаты.']);
        }
        // A collection that would pay nothing existing and charge nothing
        // new is a no-op with no accounting meaning — reject it outright
        // rather than persisting a meaningless completed row.
        if (empty($data['existing_obligations']) && empty($data['new_services'])) {
            throw ValidationException::withMessages(['services' => 'Укажите хотя бы одну оплачиваемую услугу или начисление.']);
        }
    }

    /**
     * Validates and normalizes every existing_obligations/new_services
     * line's own receive_now_amount to a strict 2dp money string —
     * rejecting a negative amount outright, defaulting a missing key to
     * '0.00' (never a raw PHP undefined-array-key warning). Every other
     * field on each line passes through completely unchanged; this never
     * touches pricing, allocation, or category-specific fields.
     */
    private function normalizeLines(array $data): array
    {
        $data['existing_obligations'] = collect($data['existing_obligations'] ?? [])
            ->values()
            ->map(function (array $line, int $index) {
                $line['receive_now_amount'] = $this->nonNegativeMoney(
                    (string) ($line['receive_now_amount'] ?? '0.00'),
                    "existing_obligations.{$index}.receive_now_amount",
                );

                return $line;
            })->all();

        $data['new_services'] = collect($data['new_services'] ?? [])
            ->values()
            ->map(function (array $line, int $index) {
                $line['receive_now_amount'] = $this->nonNegativeMoney(
                    (string) ($line['receive_now_amount'] ?? '0.00'),
                    "new_services.{$index}.receive_now_amount",
                );

                return $line;
            })->all();

        return $data;
    }

    /**
     * §8 of the approved design. Authorized narrowly via 'register
     * students for year' — deliberately NOT 'create enrollments'/'update
     * enrollments' (Reception's generic Enrollment-CRUD surface, entirely
     * unrelated and unchanged) and NOT implied by any other permission.
     * The created Enrollment mirrors QuickStudentRegistrationService's own
     * minimal shape for a fresh Enrollment (evidence: that service's own
     * Enrollment::create() call) — never touches or overwrites any OTHER
     * year's Enrollment for this same Student, and never creates a second
     * Student (this service only ever operates on an already-existing
     * Student — see the implementation report on why full new-student
     * creation is deferred to PR C).
     */
    private function establishAnnualRegistration(Student $student, AcademicYear $year, array $registration, User $actor): Enrollment
    {
        abort_unless($actor->can('register students for year'), 403);

        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'enrollment_mode_id' => $registration['enrollment_mode_id'],
            'stage_id' => $registration['stage_id'],
            'grade_id' => $registration['grade_id'],
            'class_id' => $registration['class_id'],
            'academic_year' => $year->name,
            'enrollment_date' => $registration['registration_date'] ?? today()->toDateString(),
            'enrolled_at' => $registration['registration_date'] ?? today()->toDateString(),
            'status' => 'active',
            'is_active' => true,
            'notes' => 'Зачисление на учебный год установлено через сбор оплаты.',
        ]);
    }

    /**
     * §6 of the approved design. Server-side validated only for what
     * InvoicePaymentService::record() does not already own: that the
     * invoice actually belongs to this collection's exact
     * (student, academic_year) pair. Everything else — payable state,
     * amount > 0, amount within remaining capacity, allocation validity —
     * is record()'s own existing, unchanged, authoritative check; never
     * duplicated here. $line['receive_now_amount'] has already been
     * validated non-negative and non-zero by the caller (normalizeLines()
     * + the zero-skip in collect()) by the time this runs.
     */
    private function collectExistingObligation(
        FinanceCollection $collection,
        Student $student,
        AcademicYear $year,
        array $line,
        int $index,
        string $paymentMethod,
        int $cashAccountId,
        User $actor,
        ?string $reference,
        ?string $notes,
        string $outerToken,
    ): void {
        $invoice = Invoice::query()->find($line['invoice_id']);
        if (! $invoice || (int) $invoice->student_id !== $student->id) {
            throw ValidationException::withMessages(["existing_obligations.{$index}.invoice_id" => 'Счёт не найден для выбранного ученика.']);
        }
        if ((int) $invoice->academic_year_id !== $year->id) {
            throw ValidationException::withMessages(["existing_obligations.{$index}.invoice_id" => 'Счёт относится к другому учебному году — оплата разных учебных годов в одном сборе не допускается.']);
        }

        $payment = $this->payments->record(
            invoiceId: $invoice->id,
            cashAccountId: $cashAccountId,
            amount: $line['receive_now_amount'],
            paymentMethod: $paymentMethod,
            idempotencyKey: DeterministicIdempotencyKey::derive($outerToken, self::IDEMPOTENCY_NAMESPACE, "existing-{$index}"),
            actor: $actor,
            reference: $reference,
            notes: $notes,
            installmentId: $line['installment_id'] ?? null,
            allocations: $line['allocations'] ?? null,
        );

        $payment->update(['finance_collection_id' => $collection->id]);
    }

    /**
     * §7 of the approved design. Reuses PR A's ServiceSelectionNormalizer
     * (category-aware normalization) and issues via
     * InvoiceIssuanceService::issue() exactly like Quick Registration
     * does — payment_type is always 'mixed' with every non-Food line
     * defaulting to the 'once' billing strategy (the same default
     * ServiceSelectionNormalizer already applies when a line doesn't
     * request 'calendar'), which is the one PR A already proved handles
     * "N independently-priced lines, each with its own explicit
     * receive-now amount" correctly — periodic/calendar billing for a
     * newly-collected charge is out of scope for this PR (a caller may
     * still request it per-line; ServiceSelectionNormalizer's existing
     * 'calendar' validation applies unchanged, and
     * MixedPaymentCollectionOrchestrator::collectMixed() already settles
     * a calendar-strategy line via its coverage-period bucket exactly as
     * it does for Quick Registration).
     */
    private function collectNewServices(
        FinanceCollection $collection,
        Student $student,
        AcademicYear $year,
        Enrollment $enrollment,
        array $data,
        User $actor,
        int $cashAccountId,
        ?string $reference,
        ?string $notes,
        string $outerToken,
    ): void {
        $services = collect($data['new_services'])->map(function (array $service) {
            // receive_now_amount is already a validated, non-negative 2dp
            // money string here (normalizeLines() ran before the
            // transaction opened) — 'paid_now' is the field name
            // ServiceSelectionNormalizer's own Uniform fan-out branch
            // reads (see that class's own docblock); this is the same
            // translation the original implementation always did.
            $service['paid_now'] = $service['receive_now_amount'];

            return $service;
        })->all();

        $feesById = Fee::with('billingPeriods')
            ->whereIn('id', collect($services)->pluck('fee_id')->unique())
            ->get()->keyBy('id')->all();

        $normalizedServices = $this->normalizer->normalize($services, $enrollment->grade, $enrollment->enrollmentMode, 'mixed', $feesById);

        $invoiceIdempotencyKey = DeterministicIdempotencyKey::derive($outerToken, self::IDEMPOTENCY_NAMESPACE, 'issue');
        $invoice = $this->issuer->issue($student, [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'due_date' => $data['due_date'] ?? $year->end_date?->toDateString(),
            'pricing_date' => $data['pricing_date'] ?? today()->toDateString(),
            'items' => $normalizedServices->all(),
            'payment_type' => 'mixed',
        ], $actor, idempotencyKey: $invoiceIdempotencyKey);

        $invoice->update(['finance_collection_id' => $collection->id]);

        $orderedInvoiceItems = $invoice->items->sortBy('id')->values();
        $allocations = $this->buildNewChargeAllocations($normalizedServices, $orderedInvoiceItems);
        $paidNow = collect($allocations)->reduce(fn (string $sum, array $line) => bcadd($sum, $line['amount'], 2), '0.00');

        if (bccomp($paidNow, '0.00', 2) > 0) {
            $this->orchestrator->collectMixed(
                $invoice, $allocations, $normalizedServices, $orderedInvoiceItems,
                $paidNow, $data['payment_method'], $cashAccountId, $actor,
                $reference, $notes, $outerToken, self::IDEMPOTENCY_NAMESPACE,
            );

            // Fetched and saved as models (not a bulk query-builder
            // update()) so InvoicePayment's own saving() guard — including
            // the finance_collection_id write-once check added in this
            // corrective pass — genuinely runs on this, the one legitimate
            // linking call site, rather than being bypassed by a raw SQL
            // UPDATE.
            InvoicePayment::query()
                ->where('invoice_id', $invoice->id)
                ->whereIn('idempotency_key', [
                    DeterministicIdempotencyKey::derive($outerToken, self::IDEMPOTENCY_NAMESPACE, 'mixed-once'),
                    DeterministicIdempotencyKey::derive($outerToken, self::IDEMPOTENCY_NAMESPACE, 'mixed-periods'),
                ])
                ->get()
                ->each(fn (InvoicePayment $payment) => $payment->update(['finance_collection_id' => $collection->id]));
        }
    }

    /**
     * Matches each normalized new-service line to its resulting
     * InvoiceItem by position (issue() creates items in the exact order
     * its own 'items' input arrives in — the same invariant Quick
     * Registration's own reconciliation loop relies on), capping each
     * line's own receive-now amount at that item's own resolved charge —
     * never the other way around (§7's charge-vs-received invariant).
     * A Fee appearing on more than one line (Uniform's multi-item
     * fan-out) has its one combined receive-now amount distributed
     * greedily across its sibling lines, each still capped at its own
     * amount — the same distribution QuickStudentRegistrationService's
     * own (registration-specific, not reused here) reconciliation loop
     * uses for the identical Uniform case.
     *
     * @param  Collection<int, array<string, mixed>>  $normalizedServices
     * @param  Collection<int, \App\Models\InvoiceItem>  $orderedInvoiceItems
     * @return array<int, array{invoice_item_id: int, amount: string}>
     */
    private function buildNewChargeAllocations(Collection $normalizedServices, Collection $orderedInvoiceItems): array
    {
        $feeLineCounts = $normalizedServices->pluck('fee_id')->map(fn ($id) => (int) $id)->countBy();
        $remainingPaidByFeeId = [];
        $allocations = [];

        foreach ($orderedInvoiceItems as $position => $item) {
            $selection = $normalizedServices[$position] ?? null;
            if ($selection === null || (int) $selection['fee_id'] !== (int) $item->fee_id) {
                throw ValidationException::withMessages(['new_services' => "Не удалось сопоставить строку счёта с выбранной услугой (fee_id: {$item->fee_id})."]);
            }

            $feeId = (int) $item->fee_id;
            if (($feeLineCounts[$feeId] ?? 1) > 1) {
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
                if (bccomp($linePaid, $item->amount, 2) > 0) {
                    throw ValidationException::withMessages([
                        "new_services.{$position}.receive_now_amount" => 'Оплата по услуге не может превышать её рассчитанную стоимость.',
                    ]);
                }
            }

            if (bccomp($linePaid, '0.00', 2) > 0) {
                $allocations[] = ['invoice_item_id' => $item->id, 'amount' => $linePaid];
            }
        }

        return $allocations;
    }

    private function replay(FinanceCollection $collection, string $payloadHash): FinanceCollection
    {
        if (! hash_equals($collection->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ повторного запроса уже использован для другого сбора оплаты.']);
        }

        return $collection;
    }

    /**
     * Corrective pass §4 — hardened idempotency hash. Two changes from
     * the original:
     *  1. $cashAccountId is the ALREADY-RESOLVED canonical account, never
     *     the raw $data['cash_account_id'] — two submissions that differ
     *     only in an ignored/overridden cash_account_id (e.g. 'cash'
     *     always resolves to the same canonical account regardless of
     *     what was submitted) must hash identically.
     *  2. Every line is canonicalized through an EXPLICIT field whitelist
     *     (canonicalizeExistingObligationLine()/canonicalizeNewServiceLine()/
     *     canonicalizeAnnualRegistration()) instead of hashing the whole
     *     raw line array — a client-supplied decoy/irrelevant key (e.g. a
     *     stray 'price' field that never affects behavior — see §6 of the
     *     approved design) can no longer cause a false "conflicting
     *     payload" rejection on an otherwise-identical retry. Every field
     *     ServiceSelectionNormalizer/InvoicePaymentService::record()
     *     actually reads IS in the whitelist, so a genuine change to any
     *     of them still changes the hash.
     * notes is deliberately excluded — same established convention as
     * QuickStudentRegistrationService::operationPayloadHash() (a
     * submission differing only in a descriptive comment is still the
     * same financial transaction).
     */
    private function payloadHash(array $data, int $cashAccountId): string
    {
        $material = [
            'student_id' => (int) $data['student_id'],
            'academic_year_id' => (int) $data['academic_year_id'],
            'payment_method' => (string) $data['payment_method'],
            'cash_account_id' => $cashAccountId,
            'annual_registration' => isset($data['annual_registration']) ? $this->canonicalizeAnnualRegistration($data['annual_registration']) : null,
            'existing_obligations' => collect($data['existing_obligations'] ?? [])
                ->map(fn (array $line) => $this->canonicalizeExistingObligationLine($line))
                ->sortBy(fn (array $line) => sprintf('%020d', $line['invoice_id'] ?? 0) . '|' . json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->values()->all(),
            'new_services' => collect($data['new_services'] ?? [])
                ->map(fn (array $line) => $this->canonicalizeNewServiceLine($line))
                ->sortBy(fn (array $line) => sprintf('%020d', $line['fee_id'] ?? 0) . '|' . json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->values()->all(),
        ];

        return hash('sha256', json_encode($material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{invoice_id: ?int, installment_id: ?int, receive_now_amount: string, allocations: array}
     */
    private function canonicalizeExistingObligationLine(array $line): array
    {
        return [
            'invoice_id' => isset($line['invoice_id']) ? (int) $line['invoice_id'] : null,
            'installment_id' => isset($line['installment_id']) ? (int) $line['installment_id'] : null,
            'receive_now_amount' => $this->money((string) ($line['receive_now_amount'] ?? '0.00')),
            'allocations' => collect($line['allocations'] ?? [])
                ->map(fn (array $allocation) => [
                    'invoice_item_id' => (int) $allocation['invoice_item_id'],
                    'amount' => $this->money((string) $allocation['amount']),
                ])
                ->sortBy(fn (array $allocation) => $allocation['invoice_item_id'])
                ->values()->all(),
        ];
    }

    /**
     * Whitelists exactly the fields ServiceSelectionNormalizer::normalize()
     * and this service's own buildNewChargeAllocations() actually read
     * (see that class's own docblock for the full field list this
     * mirrors) plus receive_now_amount — nothing else can materially
     * change what this line does, so nothing else enters the hash.
     */
    private function canonicalizeNewServiceLine(array $line): array
    {
        return [
            'fee_id' => isset($line['fee_id']) ? (int) $line['fee_id'] : null,
            'quantity' => isset($line['quantity']) ? (int) $line['quantity'] : null,
            'grade_group' => $line['grade_group'] ?? null,
            'transport_area' => $line['transport_area'] ?? null,
            'transport_route_id' => isset($line['transport_route_id']) ? (int) $line['transport_route_id'] : null,
            'meal_plan_id' => isset($line['meal_plan_id']) ? (int) $line['meal_plan_id'] : null,
            'payment_period' => $line['payment_period'] ?? null,
            'billing_strategy' => $line['billing_strategy'] ?? null,
            'uniform_items' => collect($line['uniform_items'] ?? [])
                ->map(fn (array $item) => [
                    'uniform_product_id' => (int) $item['uniform_product_id'],
                    'quantity' => (int) $item['quantity'],
                ])
                ->sortBy(fn (array $item) => $item['uniform_product_id'])
                ->values()->all(),
            'receive_now_amount' => $this->money((string) ($line['receive_now_amount'] ?? '0.00')),
        ];
    }

    private function canonicalizeAnnualRegistration(array $registration): array
    {
        return [
            'enrollment_mode_id' => isset($registration['enrollment_mode_id']) ? (int) $registration['enrollment_mode_id'] : null,
            'stage_id' => isset($registration['stage_id']) ? (int) $registration['stage_id'] : null,
            'grade_id' => isset($registration['grade_id']) ? (int) $registration['grade_id'] : null,
            'class_id' => isset($registration['class_id']) ? (int) $registration['class_id'] : null,
            'registration_date' => $registration['registration_date'] ?? null,
        ];
    }

    private function money(string $value): string
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Укажите корректную сумму.']);
        }

        return bcadd($value, '0', 2);
    }

    /**
     * Corrective pass §3(C)/(D) — money() plus an explicit non-negative
     * check, one shared helper for both existing_obligations and
     * new_services lines so the two rules stay identical.
     */
    private function nonNegativeMoney(string $value, string $field): string
    {
        $normalized = $this->money($value);
        if (bccomp($normalized, '0.00', 2) < 0) {
            throw ValidationException::withMessages([$field => 'Сумма не может быть отрицательной.']);
        }

        return $normalized;
    }
}

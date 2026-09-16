<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
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
 */
class FinanceCollectionService
{
    private const IDEMPOTENCY_NAMESPACE = 'finance-collection';

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
     *     existing_obligations?: array<int, array{invoice_id: int, receive_now_amount: string, installment_id?: ?int, allocations?: ?array}>,
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
        $outerToken = $data['idempotency_token'] ?? (string) Str::uuid();
        $idempotencyKey = DeterministicIdempotencyKey::derive($outerToken, self::IDEMPOTENCY_NAMESPACE, 'collection');
        $payloadHash = $this->payloadHash($data);

        // Checked before opening the transaction too — mirrors
        // InvoiceIssuanceService::issue()/QuickStudentRegistrationService::
        // register()'s own established pattern: the overwhelmingly common
        // case (a genuine first attempt) never even opens one for this.
        $existing = FinanceCollection::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $this->replay($existing, $payloadHash);
        }

        try {
            return DB::transaction(function () use ($data, $actor, $outerToken, $idempotencyKey, $payloadHash) {
                $collection = FinanceCollection::create([
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'student_id' => $data['student_id'],
                    'academic_year_id' => $data['academic_year_id'],
                    'created_by' => $actor->id,
                    'payment_method' => $data['payment_method'],
                    'cash_account_id' => $data['cash_account_id'] ?? null,
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
                $cashAccountId = $data['cash_account_id'] ?? null;
                $notes = $data['notes'] ?? null;

                // E. Existing obligations — each its own explicit
                // receive-now amount, never spread automatically.
                foreach ($data['existing_obligations'] ?? [] as $index => $line) {
                    $this->collectExistingObligation($collection, $student, $year, $line, $index, $data['payment_method'], $cashAccountId, $actor, $reference, $notes, $outerToken);
                }

                // F/G. New service charges — one Invoice, issued and priced
                // entirely server-side, then settled via the same PR A
                // MixedPaymentCollectionOrchestrator::collectMixed() shape
                // Quick Registration's own 'mixed' payment_type already
                // uses for "N once-strategy lines, each independently
                // partially paid."
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
     * duplicated here.
     */
    private function collectExistingObligation(
        FinanceCollection $collection,
        Student $student,
        AcademicYear $year,
        array $line,
        int $index,
        string $paymentMethod,
        ?int $cashAccountId,
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
            amount: $this->money((string) $line['receive_now_amount']),
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
        ?int $cashAccountId,
        ?string $reference,
        ?string $notes,
        string $outerToken,
    ): void {
        $services = collect($data['new_services'])->map(function (array $service) {
            $service['paid_now'] = $this->money((string) ($service['receive_now_amount'] ?? '0.00'));

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

            InvoicePayment::query()
                ->where('invoice_id', $invoice->id)
                ->whereIn('idempotency_key', [
                    DeterministicIdempotencyKey::derive($outerToken, self::IDEMPOTENCY_NAMESPACE, 'mixed-once'),
                    DeterministicIdempotencyKey::derive($outerToken, self::IDEMPOTENCY_NAMESPACE, 'mixed-periods'),
                ])
                ->update(['finance_collection_id' => $collection->id]);
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

    private function payloadHash(array $data): string
    {
        $material = [
            'student_id' => (int) $data['student_id'],
            'academic_year_id' => (int) $data['academic_year_id'],
            'payment_method' => (string) $data['payment_method'],
            'cash_account_id' => isset($data['cash_account_id']) ? (int) $data['cash_account_id'] : null,
            'annual_registration' => isset($data['annual_registration']) ? $this->canonicalize($data['annual_registration']) : null,
            'existing_obligations' => collect($data['existing_obligations'] ?? [])
                ->map(fn (array $line) => $this->canonicalize($line))
                ->sortBy(fn (array $line) => sprintf('%020d', $line['invoice_id'] ?? 0))
                ->values()->all(),
            'new_services' => collect($data['new_services'] ?? [])
                ->map(fn (array $line) => $this->canonicalize($line))
                ->sortBy(fn (array $line) => sprintf('%020d', $line['fee_id'] ?? 0))
                ->values()->all(),
        ];

        return hash('sha256', json_encode($material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $canonicalized = collect($value)->map(fn ($v) => $this->canonicalize($v));

            return $isList ? $canonicalized->values()->all() : $canonicalized->sortKeys()->all();
        }

        return $value;
    }

    private function money(string $value): string
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Укажите корректную сумму.']);
        }

        return bcadd($value, '0', 2);
    }
}

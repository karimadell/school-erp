<?php

namespace App\Services\Finance;

use App\Exceptions\Finance\BatchNotExecutableException;
use App\Models\BillingBatch;
use App\Models\BillingRun;
use App\Models\BillingRunItem;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Converts a previewed Mass Billing batch into real invoices, safely.
 *
 * Guarantees:
 *  - Eligibility and pricing are recalculated server-side at execution time
 *    (via MassBillingEligibilityService) — preview figures are never trusted.
 *  - Every invoice is created through InvoiceIssuanceService, so numbering,
 *    item snapshots, academic-year validation, tariff calculation, invoice_fee
 *    compatibility, installments, issue/due dates, registration-fee protection,
 *    audit logging and EGP totals are all preserved.
 *  - Idempotency: the batch row is locked for update and its status gated so a
 *    completed/processing/failed batch cannot re-issue invoices; concurrent
 *    attempts block on the row lock and then see the terminal state.
 *  - Atomicity: all invoices and run items are written in one transaction.
 *    Expected business conditions become skipped run items (not failures); any
 *    unexpected error rolls the whole transaction back — leaving zero invoices —
 *    and the failure is recorded in a separate transaction afterwards.
 */
class MassBillingExecutionService
{
    public const FAILURE_CODE = 'execution_failed';

    public function __construct(
        private BillingTargetResolver $resolver,
        private MassBillingEligibilityService $eligibility,
        private InvoiceIssuanceService $issuer,
    ) {
    }

    /**
     * @throws BatchNotExecutableException when the batch state forbids execution.
     * @throws Throwable rethrown after the failure is recorded, when invoice generation fails unexpectedly.
     */
    public function execute(BillingBatch $batch, User $actor, ?string $ip = null, ?string $userAgent = null): BillingRun
    {
        try {
            return DB::transaction(fn () => $this->generate($batch, $actor, $ip, $userAgent));
        } catch (BatchNotExecutableException $exception) {
            // State-gate rejection: nothing was written, so there is no partial
            // state to clean up and no failure to record.
            throw $exception;
        } catch (Throwable $exception) {
            // The generation transaction has fully rolled back (zero invoices).
            // Record the failure in its own transaction, then rethrow.
            $this->recordFailure($batch, $actor);

            throw $exception;
        }
    }

    private function generate(BillingBatch $batch, User $actor, ?string $ip, ?string $userAgent): BillingRun
    {
        $batch = BillingBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
        $this->assertExecutable($batch);

        $batch->update(['status' => BillingBatch::STATUS_PROCESSING]);

        $run = $batch->runs()->create([
            'trigger_type' => BillingRun::TRIGGER_MANUAL,
            'status' => BillingRun::STATUS_PROCESSING,
            'executed_by' => $actor->id,
            'started_at' => now(),
        ]);

        $studentIds = $this->resolver->resolve($batch);
        $context = $this->eligibility->context($batch, $studentIds);
        $fee = $context['fee'];
        $students = Student::query()->whereIn('id', $studentIds)->get()->keyBy('id');

        $generated = 0;
        $skipped = 0;
        $total = '0.00';

        foreach ($studentIds as $studentId) {
            $student = $students->get($studentId);
            $enrollment = $context['enrollments']->get($studentId);

            $classification = $this->eligibility->classify(
                $enrollment,
                $fee,
                $context['year_active'],
                $context['service_active'],
                $context['registration_duplicate_ids']->contains($studentId),
                (int) $batch->quantity,
                $batch->issue_date->toDateString(),
                (int) $batch->academic_year_id,
            );

            if (! $classification['eligible']) {
                $this->skippedItem($run, $batch, $student, $enrollment, $fee, $classification);
                $skipped++;

                continue;
            }

            // Canonical issuance — the single source of truth for the persisted
            // invoice. An unexpected throw here aborts the whole transaction.
            $invoice = $this->issuer->issue($student, $this->issuanceData($batch, $student, $fee), $actor, $ip, $userAgent);

            $this->generatedItem($run, $batch, $student, $enrollment, $fee, $invoice);
            $generated++;
            $total = bcadd($total, (string) $invoice->total_amount, 2);
        }

        $run->update([
            'status' => BillingRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'processed_count' => $studentIds->count(),
            'created_count' => $generated,
            'skipped_count' => $skipped,
            'failed_count' => 0,
            'total_amount' => $total,
        ]);

        $batch->update([
            'status' => BillingBatch::STATUS_COMPLETED,
            'executed_by' => $actor->id,
        ]);

        return $run;
    }

    private function assertExecutable(BillingBatch $batch): void
    {
        match ($batch->status) {
            BillingBatch::STATUS_PROCESSING => throw new BatchNotExecutableException(BatchNotExecutableException::REASON_ALREADY_PROCESSING),
            BillingBatch::STATUS_COMPLETED => throw new BatchNotExecutableException(BatchNotExecutableException::REASON_ALREADY_COMPLETED),
            BillingBatch::STATUS_FAILED => throw new BatchNotExecutableException(BatchNotExecutableException::REASON_PREVIOUSLY_FAILED),
            BillingBatch::STATUS_PREVIEWED => null,
            default => throw new BatchNotExecutableException(BatchNotExecutableException::REASON_NOT_PREVIEWED),
        };
    }

    /**
     * Invoice-issuance payload shaped exactly like StoreInvoiceRequest::validated(),
     * so the single-invoice persistence path is reused verbatim.
     *
     * @return array<string, mixed>
     */
    private function issuanceData(BillingBatch $batch, Student $student, Fee $fee): array
    {
        return [
            'student_id' => $student->id,
            'academic_year_id' => $batch->academic_year_id,
            'due_date' => $batch->due_date->toDateString(),
            'pricing_date' => $batch->issue_date->toDateString(),
            'items' => [[
                'fee_id' => $fee->id,
                'quantity' => (int) $batch->quantity,
                'grade_group' => null,
                'payment_period' => null,
                'first_last_month' => false,
                'size' => null,
                'item' => null,
                'option_type' => null,
                'option_value' => null,
            ]],
            'payment_type' => 'one_time',
            'notes' => $batch->description,
        ];
    }

    private function generatedItem(BillingRun $run, BillingBatch $batch, Student $student, ?Enrollment $enrollment, ?Fee $fee, Invoice $invoice): void
    {
        $item = $invoice->items()->first();

        $run->items()->create([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment?->id,
            'fee_id' => $fee?->id,
            'invoice_id' => $invoice->id,
            'status' => BillingRunItem::STATUS_GENERATED,
            'skip_reason' => null,
            'unit_price' => $item?->unit_price,
            'quantity' => (int) ($item?->quantity ?? $batch->quantity),
            'amount' => $invoice->total_amount,
            'context' => $this->itemContext($batch, $student, $enrollment, $fee, $item?->metadata ?? []),
        ]);
    }

    /** @param array{skip_reason: ?string} $classification */
    private function skippedItem(BillingRun $run, BillingBatch $batch, ?Student $student, ?Enrollment $enrollment, ?Fee $fee, array $classification): void
    {
        $run->items()->create([
            'student_id' => $student?->id,
            'enrollment_id' => $enrollment?->id,
            'fee_id' => $fee?->id,
            'invoice_id' => null,
            'status' => BillingRunItem::STATUS_SKIPPED,
            'skip_reason' => $classification['skip_reason'],
            'unit_price' => null,
            'quantity' => (int) $batch->quantity,
            'amount' => null,
            'context' => $this->itemContext($batch, $student, $enrollment, $fee, []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function itemContext(BillingBatch $batch, ?Student $student, ?Enrollment $enrollment, ?Fee $fee, array $metadata): array
    {
        return [
            'student_name' => $student?->full_name ?? $student?->name,
            'class_id' => $enrollment?->class_id,
            'class_name' => $this->eligibility->className($enrollment),
            'fee_name' => $fee?->name_ru,
            'pricing_date' => $batch->issue_date->toDateString(),
            'tariff_valid_from' => $metadata['tariff_valid_from'] ?? null,
            'tariff_valid_to' => $metadata['tariff_valid_to'] ?? null,
        ];
    }

    /**
     * Records the failed attempt after the generation transaction has rolled
     * back. Runs in its own transaction and stores only a stable, non-sensitive
     * failure code — never an exception trace.
     */
    private function recordFailure(BillingBatch $batch, User $actor): void
    {
        DB::transaction(function () use ($batch, $actor): void {
            $locked = BillingBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            // A concurrent attempt may have already completed this batch; never
            // clobber a successful terminal state with a failure.
            if ($locked->status === BillingBatch::STATUS_COMPLETED) {
                return;
            }

            $locked->runs()->create([
                'trigger_type' => BillingRun::TRIGGER_MANUAL,
                'status' => BillingRun::STATUS_FAILED,
                'executed_by' => $actor->id,
                'started_at' => now(),
                'finished_at' => now(),
                'processed_count' => 0,
                'created_count' => 0,
                'skipped_count' => 0,
                'failed_count' => 0,
                'total_amount' => null,
                'failure_summary' => ['code' => self::FAILURE_CODE],
            ]);

            $locked->update(['status' => BillingBatch::STATUS_FAILED]);
        });
    }
}

<?php

namespace App\Services\Finance;

use App\Models\BillingBatch;
use App\Models\Student;

/**
 * Computes a Mass Billing preview: it resolves every targeted student and
 * classifies each as eligible or skipped through MassBillingEligibilityService
 * — the same rules and server-side pricing the execution path uses — then
 * persists an informational snapshot. It creates no invoices.
 *
 * Skipped students are never silently dropped: they remain in the result with a
 * stable machine reason code. Preview amounts are informational only; execution
 * (Checkpoint 3) recalculates everything under locks and never trusts them.
 */
class MassBillingPreviewService
{
    // Reason codes are owned by MassBillingEligibilityService; these aliases
    // preserve the public constants callers/tests already reference.
    public const SKIP_YEAR_INACTIVE = MassBillingEligibilityService::SKIP_YEAR_INACTIVE;
    public const SKIP_SERVICE_INACTIVE = MassBillingEligibilityService::SKIP_SERVICE_INACTIVE;
    public const SKIP_NO_ENROLLMENT = MassBillingEligibilityService::SKIP_NO_ENROLLMENT;
    public const SKIP_ENROLLMENT_INACTIVE = MassBillingEligibilityService::SKIP_ENROLLMENT_INACTIVE;
    public const SKIP_ENROLLMENT_WITHDRAWN = MassBillingEligibilityService::SKIP_ENROLLMENT_WITHDRAWN;
    public const SKIP_ENROLLMENT_GRADUATED = MassBillingEligibilityService::SKIP_ENROLLMENT_GRADUATED;
    public const SKIP_ENROLLMENT_TRANSFERRED = MassBillingEligibilityService::SKIP_ENROLLMENT_TRANSFERRED;
    public const SKIP_REGISTRATION_DUPLICATE = MassBillingEligibilityService::SKIP_REGISTRATION_DUPLICATE;
    public const SKIP_NO_TARIFF = MassBillingEligibilityService::SKIP_NO_TARIFF;
    public const SKIP_PRICING_ERROR = MassBillingEligibilityService::SKIP_PRICING_ERROR;

    public function __construct(
        private BillingTargetResolver $resolver,
        private MassBillingEligibilityService $eligibility,
    ) {
    }

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   selected_count: int, eligible_count: int, skipped_count: int,
     *   expected_invoice_count: int, expected_total_amount: string
     * }
     */
    public function preview(BillingBatch $batch): array
    {
        $studentIds = $this->resolver->resolve($batch);

        $context = $this->eligibility->context($batch, $studentIds);
        $fee = $context['fee'];
        $students = Student::query()->whereIn('id', $studentIds)->get()->keyBy('id');

        $rows = [];
        $eligibleCount = 0;
        $expectedTotal = '0.00';

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

            if ($classification['eligible']) {
                $eligibleCount++;
                $expectedTotal = bcadd($expectedTotal, $classification['amount'], 2);
            }

            $rows[] = [
                'student_id' => $studentId,
                'student_name' => $student?->full_name ?? $student?->name ?? "#{$studentId}",
                'class_name' => $this->eligibility->className($enrollment),
                'fee_name' => $fee?->name_ru,
                'unit_price' => $classification['unit_price'],
                'quantity' => (int) $batch->quantity,
                'total' => $classification['amount'],
                'eligible' => $classification['eligible'],
                'skip_reason' => $classification['skip_reason'],
            ];
        }

        $selectedCount = count($rows);
        $skippedCount = $selectedCount - $eligibleCount;

        $summary = [
            'rows' => $rows,
            'selected_count' => $selectedCount,
            'eligible_count' => $eligibleCount,
            'skipped_count' => $skippedCount,
            'expected_invoice_count' => $eligibleCount,
            'expected_total_amount' => $expectedTotal,
        ];

        $this->persist($batch, $summary);

        return $summary;
    }

    /** @param array<string, mixed> $summary */
    private function persist(BillingBatch $batch, array $summary): void
    {
        $batch->forceFill([
            'status' => BillingBatch::STATUS_PREVIEWED,
            'selected_count' => $summary['selected_count'],
            'eligible_count' => $summary['eligible_count'],
            'skipped_count' => $summary['skipped_count'],
            'expected_invoice_count' => $summary['expected_invoice_count'],
            'expected_total_amount' => $summary['expected_total_amount'],
            'preview_snapshot' => $summary['rows'],
            'previewed_at' => now(),
        ])->save();
    }
}

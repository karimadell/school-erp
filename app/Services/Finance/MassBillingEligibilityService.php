<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\BillingBatch;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for Mass Billing eligibility and server-side pricing.
 *
 * Both the preview (informational) and the execution (authoritative) paths use
 * this service so a student is classified by exactly the same domain rules in
 * both places: academic-year activity, active enrollment (which encodes
 * withdrawn/graduated/transferred via is_active), service activity,
 * registration-fee duplicate protection, and tariff resolution through
 * InvoiceCalculationService. Skip decisions carry stable machine reason codes;
 * Russian text is never used as the identifier.
 */
class MassBillingEligibilityService
{
    public const SKIP_YEAR_INACTIVE = 'year_inactive';
    public const SKIP_SERVICE_INACTIVE = 'service_inactive';
    public const SKIP_NO_ENROLLMENT = 'no_enrollment';
    public const SKIP_ENROLLMENT_INACTIVE = 'enrollment_inactive';
    public const SKIP_ENROLLMENT_WITHDRAWN = 'enrollment_withdrawn';
    public const SKIP_ENROLLMENT_GRADUATED = 'enrollment_graduated';
    public const SKIP_ENROLLMENT_TRANSFERRED = 'enrollment_transferred';
    public const SKIP_REGISTRATION_DUPLICATE = 'registration_duplicate';
    public const SKIP_NO_TARIFF = 'no_tariff';
    public const SKIP_PRICING_ERROR = 'pricing_error';
    // Food flexible-duration corrective pass: Mass Billing has no
    // duration-mode selection UI/concept at all (no per-student day/
    // school_week/teaching_days/month/custom_range choice) — Food is
    // therefore always skipped here, fail-closed, rather than ever
    // reaching InvoiceCalculationService::calculate() without a
    // food_resolution and silently pricing off whatever quantity this
    // service happens to submit (never a real day-count).
    public const SKIP_FOOD_NOT_SUPPORTED = 'food_not_supported';

    private const TUITION_CATEGORIES = [
        Fee::CATEGORY_TUITION,
        Fee::CATEGORY_TUITION_REGULAR,
        Fee::CATEGORY_TUITION_FAMILY,
        Fee::CATEGORY_TUITION_EXTERNAL,
    ];

    public function __construct(
        private InvoiceCalculationService $calculator,
    ) {
    }

    /**
     * Shared batch-level context for classifying a set of students.
     *
     * @param  Collection<int, int>  $studentIds
     * @return array{
     *   year: ?AcademicYear, fee: ?Fee, year_active: bool, service_active: bool,
     *   enrollments: Collection<int, Enrollment>, registration_duplicate_ids: Collection<int, int>
     * }
     */
    public function context(BillingBatch $batch, Collection $studentIds): array
    {
        $year = AcademicYear::find($batch->academic_year_id);
        $fee = Fee::find($batch->fee_id);

        return [
            'year' => $year,
            'fee' => $fee,
            'year_active' => (bool) ($year?->is_active),
            'service_active' => (bool) ($fee?->is_active),
            'enrollments' => $this->enrollmentsFor($batch, $studentIds),
            'registration_duplicate_ids' => $this->registrationDuplicateIds($batch, $fee, $studentIds),
        ];
    }

    /**
     * @return array{eligible: bool, skip_reason: ?string, unit_price: ?string, amount: ?string, tariff_valid_from: ?string, tariff_valid_to: ?string}
     */
    public function classify(
        ?Enrollment $enrollment,
        ?Fee $fee,
        bool $yearActive,
        bool $feeActive,
        bool $registrationDuplicate,
        int $quantity,
        string $pricingDate,
        int $academicYearId,
    ): array {
        if (! $yearActive) {
            return $this->skip(self::SKIP_YEAR_INACTIVE);
        }

        if (! $feeActive || ! $fee) {
            return $this->skip(self::SKIP_SERVICE_INACTIVE);
        }

        if (! $enrollment) {
            return $this->skip(self::SKIP_NO_ENROLLMENT);
        }

        if (! $enrollment->is_active) {
            return $this->skip($this->inactiveEnrollmentReason($enrollment));
        }

        if ($fee->category === Fee::CATEGORY_REGISTRATION && $registrationDuplicate) {
            return $this->skip(self::SKIP_REGISTRATION_DUPLICATE);
        }

        if ($fee->category === Fee::CATEGORY_FOOD) {
            return $this->skip(self::SKIP_FOOD_NOT_SUPPORTED);
        }

        try {
            $calculation = $this->calculator->calculate(
                [$this->itemFor($fee, $enrollment, $quantity)],
                null,
                null,
                '0',
                $pricingDate,
                $academicYearId,
            );
        } catch (ValidationException $exception) {
            return $this->skip($this->pricingFailureReason($exception));
        }

        $line = $calculation['line_items'][0];

        return [
            'eligible' => true,
            'skip_reason' => null,
            'unit_price' => $line['unit_price'],
            'amount' => $line['amount'],
            'tariff_valid_from' => $line['tariff_valid_from'] ?? null,
            'tariff_valid_to' => $line['tariff_valid_to'] ?? null,
        ];
    }

    /**
     * The item selection the invoice calculation path expects for this batch's
     * single service and a given enrollment.
     *
     * @return array<string, mixed>
     */
    public function itemFor(Fee $fee, Enrollment $enrollment, int $quantity): array
    {
        $item = [
            'fee_id' => $fee->id,
            'quantity' => $quantity,
            'enrollment_mode_id' => $enrollment->enrollment_mode_id,
        ];

        // Mirror the single-invoice path: tuition without an explicit
        // grade_group is priced against the enrollment's grade.
        if (in_array($fee->category, self::TUITION_CATEGORIES, true)) {
            $item['grade_id'] = $enrollment->grade_id;
        }

        return $item;
    }

    public function className(?Enrollment $enrollment): ?string
    {
        $class = $enrollment?->schoolClass;

        return $class?->name_ru ?? $class?->code;
    }

    private function inactiveEnrollmentReason(Enrollment $enrollment): string
    {
        return match ($enrollment->status) {
            'withdrawn' => self::SKIP_ENROLLMENT_WITHDRAWN,
            'graduated' => self::SKIP_ENROLLMENT_GRADUATED,
            'transferred' => self::SKIP_ENROLLMENT_TRANSFERRED,
            default => self::SKIP_ENROLLMENT_INACTIVE,
        };
    }

    private function pricingFailureReason(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            foreach ((array) $messages as $message) {
                if (str_contains($message, 'тариф')) {
                    return self::SKIP_NO_TARIFF;
                }
            }
        }

        return self::SKIP_PRICING_ERROR;
    }

    /** @return array{eligible: bool, skip_reason: string, unit_price: null, amount: null, tariff_valid_from: null, tariff_valid_to: null} */
    private function skip(string $reason): array
    {
        return [
            'eligible' => false, 'skip_reason' => $reason,
            'unit_price' => null, 'amount' => null,
            'tariff_valid_from' => null, 'tariff_valid_to' => null,
        ];
    }

    /**
     * @param  Collection<int, int>  $studentIds
     * @return Collection<int, Enrollment>
     */
    private function enrollmentsFor(BillingBatch $batch, Collection $studentIds): Collection
    {
        return Enrollment::query()
            ->where('academic_year_id', $batch->academic_year_id)
            ->whereIn('student_id', $studentIds)
            ->with('schoolClass')
            ->get()
            ->keyBy('student_id');
    }

    /**
     * Students who already have an invoice item for this registration fee in
     * the batch's academic year — the same duplicate protection the
     * single-invoice path enforces.
     *
     * @param  Collection<int, int>  $studentIds
     * @return Collection<int, int>
     */
    private function registrationDuplicateIds(BillingBatch $batch, ?Fee $fee, Collection $studentIds): Collection
    {
        if (! $fee || $fee->category !== Fee::CATEGORY_REGISTRATION || $studentIds->isEmpty()) {
            return collect();
        }

        return Invoice::query()
            ->where('academic_year_id', $batch->academic_year_id)
            ->whereIn('student_id', $studentIds)
            ->whereHas('items', fn ($query) => $query->where('fee_id', $fee->id))
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->unique();
    }
}

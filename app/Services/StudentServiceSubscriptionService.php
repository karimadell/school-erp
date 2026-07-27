<?php

namespace App\Services;

use App\Exceptions\DuplicateSubscriptionException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FinancePolicySetting;
use App\Models\StudentServiceSubscription;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * The single entry point for subscribing a student's enrollment to a
 * service (Fee). Encodes every Batch 5 policy decision in one place rather
 * than scattering checks across controllers, so every caller (dashboard,
 * Filament, future API) gets the same guarantees.
 */
class StudentServiceSubscriptionService
{
    public function __construct(
        protected StudentBalanceService $balanceService = new StudentBalanceService()
    ) {
    }

    /**
     * @throws DuplicateSubscriptionException
     * @throws InsufficientBalanceException
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function subscribe(
        Enrollment $enrollment,
        Fee $fee,
        array $attributes = [],
        ?User $actingUser = null
    ): StudentServiceSubscription {
        // Policy decision 3: reuse Enrollment's existing one-per-year
        // guarantee — a subscription is unique per (enrollment, fee), which
        // is exactly "one per (student, academic_year) per service".
        if (StudentServiceSubscription::where('enrollment_id', $enrollment->id)
            ->where('fee_id', $fee->id)
            ->exists()
        ) {
            throw new DuplicateSubscriptionException(
                "Enrollment #{$enrollment->id} is already subscribed to fee #{$fee->id}."
            );
        }

        // Policy decision 4: overdue-balance blocking, with per-fee
        // exceptions requiring no schema change (Fee::exempt_from_balance_block).
        if (! $fee->exempt_from_balance_block) {
            $this->assertBalanceAllowsNewSubscription($enrollment);
        }

        $negotiatedPrice = $attributes['negotiated_price'] ?? null;

        if (! is_null($negotiatedPrice)) {
            $this->assertNegotiatedPriceIsAuthorized($attributes, $actingUser);
            $attributes['negotiated_by'] = $actingUser->id;
        }

        return StudentServiceSubscription::create(array_merge($attributes, [
            'enrollment_id' => $enrollment->id,
            'fee_id' => $fee->id,
        ]));
    }

    protected function assertBalanceAllowsNewSubscription(Enrollment $enrollment): void
    {
        $settings = FinancePolicySetting::current();
        $student = $enrollment->student;

        if (! is_null($settings->overdue_block_threshold_amount)) {
            $balance = $this->balanceService->outstandingBalance($student);

            if ($balance > (float) $settings->overdue_block_threshold_amount) {
                throw new InsufficientBalanceException(
                    "Student #{$student->id}'s outstanding balance ({$balance}) exceeds the configured threshold."
                );
            }
        }

        if (! is_null($settings->overdue_block_threshold_days)) {
            if ($this->balanceService->hasInvoiceOverdueByMoreThan($student, (int) $settings->overdue_block_threshold_days)) {
                throw new InsufficientBalanceException(
                    "Student #{$student->id} has an invoice overdue by more than {$settings->overdue_block_threshold_days} days."
                );
            }
        }
    }

    /**
     * Policy decision 6: a dedicated permission, a required reason, and the
     * acting user recorded — no approval workflow in this batch.
     */
    protected function assertNegotiatedPriceIsAuthorized(array $attributes, ?User $actingUser): void
    {
        if (empty($attributes['negotiated_reason'])) {
            throw new InvalidArgumentException('A reason is required when setting a negotiated price.');
        }

        if (! $actingUser || ! $actingUser->can('override service prices')) {
            throw new AuthorizationException('You are not authorized to override service prices.');
        }
    }
}

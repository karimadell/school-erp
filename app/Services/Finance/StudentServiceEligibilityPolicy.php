<?php

namespace App\Services\Finance;

use App\Models\Fee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The single authority for which Fees may be SOLD (newly charged) to a
 * student, per business context. Keyed only on stable domain metadata
 * (Fee.category, Fee.is_active, Fee.is_test_data) — never on a display
 * name or a hardcoded id.
 *
 * Two explicit catalogs, because they legitimately differ:
 *
 *  - additionalService(): an ordinary, mid-year optional service for an
 *    already-enrolled student (Unified Collection new services, Charge &
 *    Collect, Student Stolovaya). Tuition (canonical and legacy) and the
 *    annual Registration fee belong to year setup only, and
 *    activity/excursion Fees are not sold through ordinary workflows.
 *
 *  - yearSetup(): establishing a student's academic year (Quick
 *    Registration, Unified Collection annual registration, Classic
 *    invoices, School Enrollment). Canonical tuition and registration are
 *    allowed here; activities and legacy tuition variants are not.
 *
 * Both catalogs require is_active and exclude is_test_data, enforced on
 * the listing query AND on submitted fee ids (assert*), so hiding a Fee in
 * the UI is never the only protection.
 *
 * Deliberately NOT used for: collecting existing debt (an already-issued
 * invoice stays payable whatever its Fee's current eligibility), tariff
 * maintenance (FinanceTariffController keeps NewSaleFeePolicy so activity
 * tariffs stay maintainable), or Mass Billing.
 */
class StudentServiceEligibilityPolicy
{
    public const CONTEXT_ADDITIONAL_SERVICE = 'additional_service';

    public const CONTEXT_YEAR_SETUP = 'year_setup';

    public function __construct(private NewSaleFeePolicy $newSaleFeePolicy) {}

    public function applyAdditionalService(Builder $query): Builder
    {
        return $this->apply($query, self::CONTEXT_ADDITIONAL_SERVICE);
    }

    public function applyYearSetup(Builder $query): Builder
    {
        return $this->apply($query, self::CONTEXT_YEAR_SETUP);
    }

    public function apply(Builder $query, string $context): Builder
    {
        return $this->newSaleFeePolicy->apply($query)
            ->where('is_active', true)
            ->where('is_test_data', false)
            ->whereNotIn('category', $this->excludedCategories($context));
    }

    public function isEligible(Fee $fee, string $context): bool
    {
        return $this->rejectionMessage($fee, $context) === null;
    }

    public function assertEligible(Fee $fee, string $context, string $field = 'fees'): void
    {
        $message = $this->rejectionMessage($fee, $context);
        if ($message !== null) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /** @param iterable<int, int|string|null> $ids */
    public function assertEligibleIds(iterable $ids, string $context, string $field = 'fees'): void
    {
        $ids = collect($ids)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        /** @var Collection<int, Fee> $fees */
        $fees = Fee::query()->whereIn('id', $ids)->get();
        foreach ($fees as $fee) {
            $this->assertEligible($fee, $context, $field);
        }
    }

    /** @return array<int, string> */
    private function excludedCategories(string $context): array
    {
        return match ($context) {
            self::CONTEXT_ADDITIONAL_SERVICE => array_values(array_unique([
                Fee::CATEGORY_ACTIVITY,
                Fee::CATEGORY_REGISTRATION,
                ...TuitionEnrollmentModePricing::TUITION_CATEGORIES,
            ])),
            self::CONTEXT_YEAR_SETUP => [Fee::CATEGORY_ACTIVITY],
            default => throw new \InvalidArgumentException("Unknown student service eligibility context [{$context}]."),
        };
    }

    private function rejectionMessage(Fee $fee, string $context): ?string
    {
        if (in_array($fee->category, $this->newSaleFeePolicy->legacyTuitionCategories(), true)) {
            return 'Для новых начислений используется единая услуга «Обучение»; устаревшая отдельная услуга недоступна.';
        }
        if (! $fee->is_active) {
            return 'Услуга неактивна и недоступна для новых начислений.';
        }
        if ($fee->is_test_data) {
            return 'Тестовая услуга недоступна для начислений.';
        }
        if ($fee->category === Fee::CATEGORY_ACTIVITY) {
            return 'Мероприятия и экскурсии не оформляются через обычные начисления.';
        }

        if ($context === self::CONTEXT_ADDITIONAL_SERVICE) {
            if (TuitionEnrollmentModePricing::isTuitionCategory($fee->category)) {
                return 'Обучение начисляется при зачислении на учебный год, а не как дополнительная услуга.';
            }
            if ($fee->category === Fee::CATEGORY_REGISTRATION) {
                return 'Регистрационный взнос начисляется при зачислении на учебный год, а не как дополнительная услуга.';
            }
        }

        // Validates the context name for the yearSetup path too.
        $this->excludedCategories($context);

        return null;
    }
}

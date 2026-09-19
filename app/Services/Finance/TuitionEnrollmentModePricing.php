<?php

namespace App\Services\Finance;

use App\Models\EnrollmentMode;
use App\Models\Fee;
use Illuminate\Validation\ValidationException;

/**
 * The single place that turns a submitted EnrollmentMode CODE into
 * FeePrice's own option_type/option_value pair for Tuition-category
 * pricing — shared by the Filament FeePrice admin form
 * (CreateFeePrice::mutateFormDataBeforeCreate()) and the Dashboard-native
 * tariff create screen (StoreFinanceTariffRequest) so this rule can never
 * drift between the two UIs. Never trusts a submitted code directly —
 * always re-verified against real EnrollmentMode.code master data. A
 * blank code is a legitimate, fully-supported request for a generic
 * (non-mode-scoped) tariff, not an error.
 */
class TuitionEnrollmentModePricing
{
    /** @var array<int, string> */
    public const TUITION_CATEGORIES = [
        Fee::CATEGORY_TUITION,
        Fee::CATEGORY_TUITION_REGULAR,
        Fee::CATEGORY_TUITION_FAMILY,
        Fee::CATEGORY_TUITION_EXTERNAL,
    ];

    public static function isTuitionCategory(?string $category): bool
    {
        return in_array($category, self::TUITION_CATEGORIES, true);
    }

    /**
     * @return array{option_type: ?string, option_value: ?string}
     *
     * @throws ValidationException when a non-blank code does not match a
     *                             real EnrollmentMode.code.
     */
    public static function resolve(mixed $submittedCode, string $field = 'option_value'): array
    {
        if (blank($submittedCode)) {
            return ['option_type' => null, 'option_value' => null];
        }

        $mode = EnrollmentMode::query()->where('code', $submittedCode)->first();
        if (! $mode) {
            throw ValidationException::withMessages([$field => 'Недопустимая форма обучения.']);
        }

        return ['option_type' => 'enrollment_mode', 'option_value' => $mode->code];
    }
}

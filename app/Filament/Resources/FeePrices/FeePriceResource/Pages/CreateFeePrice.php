<?php

namespace App\Filament\Resources\FeePrices\FeePriceResource\Pages;

use App\Filament\Resources\FeePrices\FeePriceResource;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Services\Finance\TuitionEnrollmentModePricing;
use Filament\Resources\Pages\CreateRecord;

class CreateFeePrice extends CreateRecord
{
    protected static string $resource = FeePriceResource::class;

    /**
     * Trusted, server-side-validated query-string DEFAULTS only (Scope D)
     * — never authoritative, never bypassing the form's own normal
     * validation on submit. Every value is re-checked against real master
     * data before being used to prefill a field; an unrecognized value is
     * simply dropped rather than reflected. Uses fillPartially() so only
     * the recognized keys are touched — every other field (currency,
     * is_active, …) keeps its own ordinary default() untouched.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        $prefill = array_filter([
            'fee_id' => request()->integer('fee_id') ?: null,
            'academic_year_id' => request()->integer('academic_year_id') ?: null,
            'grade_id' => request()->integer('grade_id') ?: null,
            'grade_group' => in_array(request()->query('grade_group'), FeePrice::GRADE_GROUPS, true)
                ? request()->query('grade_group') : null,
            'payment_period' => array_key_exists((string) request()->query('payment_period'), FeePriceResource::paymentPeriodLabels())
                ? request()->query('payment_period') : null,
            'option_value' => EnrollmentMode::query()->where('code', request()->query('enrollment_mode'))->value('code'),
        ], fn ($value) => $value !== null);

        if ($prefill !== []) {
            $this->form->fillPartially($prefill, array_keys($prefill));
        }
    }

    /**
     * The Study Mode Select (Scope A) shares the `option_value` column
     * with Transport/Food's own fields — for a Tuition Fee, its submitted
     * value is a study-mode code, never a raw option_value. Re-validated
     * against the database (never trusted from form/query-string state
     * alone) via the SAME shared rule the Dashboard-native tariff create
     * screen uses (TuitionEnrollmentModePricing) — left blank, both
     * option_type/option_value are forced to null so a plain generic
     * Tuition tariff remains fully supported, exactly as before.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $category = Fee::query()->whereKey($data['fee_id'] ?? null)->value('category');
        if (! TuitionEnrollmentModePricing::isTuitionCategory($category)) {
            return $data;
        }

        $resolved = TuitionEnrollmentModePricing::resolve($data['option_value'] ?? null);
        $data['option_type'] = $resolved['option_type'];
        $data['option_value'] = $resolved['option_value'];

        return $data;
    }
}

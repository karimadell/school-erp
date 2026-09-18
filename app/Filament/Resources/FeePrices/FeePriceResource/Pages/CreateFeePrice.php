<?php

namespace App\Filament\Resources\FeePrices\FeePriceResource\Pages;

use App\Filament\Resources\FeePrices\FeePriceResource;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateFeePrice extends CreateRecord
{
    protected static string $resource = FeePriceResource::class;

    private const TUITION_CATEGORIES = [
        Fee::CATEGORY_TUITION,
        Fee::CATEGORY_TUITION_REGULAR,
        Fee::CATEGORY_TUITION_FAMILY,
        Fee::CATEGORY_TUITION_EXTERNAL,
    ];

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
     * against the database here (never trusted from form/query-string
     * state alone) and translated into the real option_type/option_value
     * pair; left blank, both are forced to null so a plain generic
     * Tuition tariff remains fully supported, exactly as before.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $category = Fee::query()->whereKey($data['fee_id'] ?? null)->value('category');
        if (! in_array($category, self::TUITION_CATEGORIES, true)) {
            return $data;
        }

        $code = $data['option_value'] ?? null;
        if (blank($code)) {
            $data['option_type'] = null;
            $data['option_value'] = null;

            return $data;
        }

        $mode = EnrollmentMode::query()->where('code', $code)->first();
        if (! $mode) {
            throw ValidationException::withMessages(['option_value' => 'Недопустимая форма обучения.']);
        }

        $data['option_type'] = 'enrollment_mode';
        $data['option_value'] = $mode->code;

        return $data;
    }
}

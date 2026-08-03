<?php

namespace App\Http\Requests;

use App\Models\Fee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportFinancePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage fee prices') === true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', Rule::in(self::categories())],
            'include_inactive' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'academic_year_id.required' => 'Выберите учебный год.',
            'academic_year_id.exists' => 'Выбранный учебный год не найден.',
            'categories.*.in' => 'Выбрана недопустимая категория услуг.',
        ];
    }

    public static function categories(): array
    {
        return [
            Fee::CATEGORY_REGISTRATION, Fee::CATEGORY_TUITION, Fee::CATEGORY_TUITION_REGULAR,
            Fee::CATEGORY_TUITION_FAMILY, Fee::CATEGORY_TRANSPORT, Fee::CATEGORY_FOOD,
            Fee::CATEGORY_TUITION_EXTERNAL, Fee::CATEGORY_UNIFORM, Fee::CATEGORY_BOOKS,
            Fee::CATEGORY_EXTRA_CLASSES, Fee::CATEGORY_ACTIVITY, Fee::CATEGORY_OTHER,
        ];
    }
}

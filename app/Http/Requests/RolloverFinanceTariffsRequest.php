<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RolloverFinanceTariffsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage fee prices') === true;
    }

    public function rules(): array
    {
        return [
            'source_academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'target_academic_year_id' => ['required', 'integer', 'different:source_academic_year_id', 'exists:academic_years,id'],
            'confirmed' => $this->routeIs('dashboard.finance.tariffs.rollover.store')
                ? ['required', 'accepted']
                : ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_academic_year_id.required' => 'Выберите исходный учебный год.',
            'target_academic_year_id.required' => 'Выберите целевой учебный год.',
            'target_academic_year_id.different' => 'Исходный и целевой учебные годы должны отличаться.',
            'confirmed.required' => 'Подтвердите создание новых тарифов.',
            'confirmed.accepted' => 'Подтвердите создание новых тарифов.',
        ];
    }
}

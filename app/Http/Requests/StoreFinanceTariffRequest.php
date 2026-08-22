<?php

namespace App\Http\Requests;

use App\Models\AcademicYear;
use App\Models\FeePrice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFinanceTariffRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manage fee prices') === true; }

    protected function prepareForValidation(): void { $this->merge(['currency' => 'EGP', 'is_active' => $this->boolean('is_active')]); }

    public function rules(): array
    {
        return [
            'fee_id' => ['required', 'integer', 'exists:fees,id'], 'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'], 'currency' => ['required', Rule::in(['EGP'])],
            'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'is_active' => ['required', 'boolean'],
            'change_reason' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:2000'],
            'grade_id' => ['nullable', 'integer', 'exists:grades,id'], 'grade_group' => ['nullable', Rule::in(FeePrice::GRADE_GROUPS)],
            'payment_period' => ['nullable', 'string', 'max:50'], 'option_type' => ['nullable', 'string', 'max:100'],
            'option_value' => ['nullable', 'string', 'max:150'], 'item' => ['nullable', 'string', 'max:100'], 'size' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) return;
            $year = AcademicYear::find($this->integer('academic_year_id'));
            $start = $this->date('start_date'); $end = $this->filled('end_date') ? $this->date('end_date') : null;
            if (! $year || $start->gt($year->end_date) || ($end && $end->gt($year->end_date))) {
                $validator->errors()->add('start_date', 'Тариф может начинаться до учебного года, но не может действовать после его окончания.'); return;
            }
            $dimensions = ['fee_id', 'academic_year_id', 'grade_id', 'grade_group', 'payment_period', 'option_type', 'option_value', 'item', 'size'];
            $query = FeePrice::query()->where('is_active', true);
            foreach ($dimensions as $field) {
                $value = $this->input($field);
                $value === null || $value === '' ? $query->whereNull($field) : $query->where($field, $value);
            }
            $query->whereDate('start_date', '<=', ($end ?? $year->end_date)->toDateString())
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start->toDateString()));
            if ($query->exists()) $validator->errors()->add('start_date', 'Период тарифа пересекается с существующим тарифом с такими же параметрами.');
        }];
    }

    public function messages(): array
    {
        return ['fee_id.required' => 'Выберите услугу.', 'academic_year_id.required' => 'Выберите учебный год.', 'amount.required' => 'Укажите цену.', 'amount.gt' => 'Цена должна быть больше нуля.', 'start_date.required' => 'Укажите дату начала действия тарифа.'];
    }
}

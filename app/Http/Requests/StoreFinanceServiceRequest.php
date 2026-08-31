<?php

namespace App\Http\Requests;

use App\Models\Fee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinanceServiceRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manage fees') === true; }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_non_refundable' => $this->boolean('is_non_refundable')]);
    }

    public function rules(): array
    {
        return [
            'name_ru' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in([Fee::CATEGORY_REGISTRATION, Fee::CATEGORY_TUITION, Fee::CATEGORY_TUITION_REGULAR, Fee::CATEGORY_TUITION_FAMILY, Fee::CATEGORY_TUITION_EXTERNAL, Fee::CATEGORY_TRANSPORT, Fee::CATEGORY_FOOD, Fee::CATEGORY_UNIFORM, Fee::CATEGORY_BOOKS, Fee::CATEGORY_EXTRA_CLASSES, Fee::CATEGORY_ACTIVITY, Fee::CATEGORY_OTHER])],
            'type' => ['required', Rule::in(['monthly', 'yearly', 'service'])],
            'payment_period' => ['nullable', Rule::in(['once', 'daily', 'monthly', 'quarterly', 'term', 'yearly', 'package'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'is_non_refundable' => ['required', 'boolean'],
            'amount' => ['prohibited'], 'base_price' => ['prohibited'],
            // Finance V2, Phase 2B — service-aware billing schedules: which
            // billing periods this Fee allows, and (only meaningful when
            // 'custom_plan' is among them) which specific PaymentPlan(s)
            // are explicitly assigned to it. A PaymentPlan is never offered
            // to a Fee that isn't listed here.
            'billing_periods' => ['nullable', 'array'],
            'billing_periods.*' => [Rule::in([
                \App\Models\FeeBillingPeriod::PERIOD_ONCE,
                \App\Models\FeeBillingPeriod::PERIOD_MONTHLY,
                \App\Models\FeeBillingPeriod::PERIOD_QUARTERLY,
                \App\Models\FeeBillingPeriod::PERIOD_YEARLY,
                \App\Models\FeeBillingPeriod::PERIOD_CUSTOM_PLAN,
            ])],
            'payment_plan_ids' => ['nullable', 'array'],
            'payment_plan_ids.*' => ['integer', 'exists:payment_plans,id'],
        ];
    }

    public function messages(): array
    {
        return ['name_ru.required' => 'Укажите название услуги на русском языке.', 'category.required' => 'Выберите категорию услуги.', 'type.required' => 'Выберите тип начисления.'];
    }
}

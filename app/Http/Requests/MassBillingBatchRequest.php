<?php

namespace App\Http\Requests;

use App\Models\BillingBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MassBillingBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage invoices') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'fee_id' => ['required', 'integer', 'exists:fees,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'description' => ['nullable', 'string', 'max:1000'],

            'target_mode' => ['required', Rule::in([BillingBatch::TARGET_MODE_ALL, BillingBatch::TARGET_MODE_CLASSES])],
            'class_ids' => ['array', 'required_if:target_mode,'.BillingBatch::TARGET_MODE_CLASSES],
            'class_ids.*' => ['integer', 'distinct', 'exists:classes,id'],
            'include_student_ids' => ['nullable', 'array'],
            'include_student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
            'exclude_student_ids' => ['nullable', 'array'],
            'exclude_student_ids.*' => ['integer', 'distinct', 'exists:students,id'],

            // F4D-A is tariff-only: no arbitrary price/amount override is accepted.
            'unit_price' => ['prohibited'],
            'amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'override_price' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'class_ids.required_if' => __('mass_billing.validation.classes_required'),
            'due_date.after_or_equal' => __('mass_billing.validation.due_after_issue'),
        ];
    }

    /**
     * Friendly Russian-first attribute names so the shared validation.php
     * messages read naturally.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'academic_year_id' => __('mass_billing.fields.academic_year'),
            'fee_id' => __('mass_billing.fields.service'),
            'quantity' => __('mass_billing.fields.quantity'),
            'issue_date' => __('mass_billing.fields.issue_date'),
            'due_date' => __('mass_billing.fields.due_date'),
            'description' => __('mass_billing.fields.description'),
            'target_mode' => __('mass_billing.fields.target_mode'),
            'class_ids' => __('mass_billing.fields.classes'),
            'class_ids.*' => __('mass_billing.fields.classes'),
            'include_student_ids' => __('mass_billing.fields.include_students'),
            'include_student_ids.*' => __('mass_billing.fields.include_students'),
            'exclude_student_ids' => __('mass_billing.fields.exclude_students'),
            'exclude_student_ids.*' => __('mass_billing.fields.exclude_students'),
        ];
    }
}

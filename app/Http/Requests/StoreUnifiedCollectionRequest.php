<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Unified Cashier Workspace (PR C1) — SHAPE-ONLY validation plus the two
 * narrow UI-level constraints explicitly approved for this HTTP workflow
 * (see the class docblock on UnifiedCollectionController). Ownership,
 * cross-student/cross-year rejection, capacity, canonical pricing,
 * canonical cash-account resolution, annual-registration authorization,
 * transactionality and idempotency all remain FinanceCollectionService's
 * job — never duplicated here.
 *
 * Money-amount fields use a plain non-negative-decimal regex rather than
 * Laravel's 'numeric'/'decimal' rules so a value like "1e5" (scientific
 * notation, technically numeric to PHP) can never pass — the exact same
 * strict format FinanceCollectionService::money() itself requires, so a
 * request this class accepts can never fail that stricter check for a
 * format reason.
 */
class StoreUnifiedCollectionRequest extends FormRequest
{
    private const MONEY_REGEX = '/^\d+(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return $this->user()?->can('manage invoices') ?? false;
    }

    protected function prepareForValidation(): void
    {
        // A row the operator left untouched submits an empty string for
        // receive_now_amount — normalized to '0.00' here so the money
        // regex below never has to special-case blank, and so the
        // controller's own "omit zero existing-obligation lines" step
        // (see UnifiedCollectionController::store()) always has a real
        // decimal string to compare against.
        $existing = collect($this->input('existing_obligations', []))
            ->map(fn ($line) => is_array($line) ? array_merge($line, [
                'receive_now_amount' => filled($line['receive_now_amount'] ?? null) ? $line['receive_now_amount'] : '0.00',
            ]) : $line)
            ->all();

        $this->merge(['existing_obligations' => $existing]);
    }

    public function rules(): array
    {
        return [
            // student_id is deliberately NOT a request field — it comes
            // exclusively from the route-bound {student} model
            // (UnifiedCollectionController::store()), never from
            // user-controllable request data.
            'idempotency_token' => ['required', 'uuid'],
            'academic_year_id' => ['required', 'integer'],
            'payment_method' => ['required', Rule::in(['cash', 'bank', 'card', 'transfer', 'instapay'])],
            'cash_account_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'existing_obligations' => ['nullable', 'array'],
            'existing_obligations.*.invoice_id' => ['required', 'integer'],
            'existing_obligations.*.receive_now_amount' => ['required', 'regex:'.self::MONEY_REGEX],
            // Present only for a row the workspace rendered with a known
            // invoice_item_id (see StudentObligationsViewModel) — a single
            // entry naming that same item, so InvoicePaymentService::
            // record() is never left to guess which service this money
            // pays down. Absent for the "whole invoice" (ambiguous) row
            // shape, matching record()'s own existing unallocated-payment
            // path exactly.
            'existing_obligations.*.allocations' => ['nullable', 'array'],
            'existing_obligations.*.allocations.*.invoice_item_id' => ['required', 'integer'],
            'existing_obligations.*.allocations.*.amount' => ['required', 'regex:'.self::MONEY_REGEX],

            'new_services' => ['nullable', 'array'],
            'new_services.*.fee_id' => ['required', 'integer'],
            'new_services.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Corrective decision (§3 of the approved design) — a new
            // service selected in this cashier workspace must be
            // genuinely collected against, never a charge-only, zero-
            // received line. FinanceCollectionService itself still
            // supports receive_now_amount = 0 for a new charge (that
            // capability is intentionally unchanged) — this constraint is
            // specific to THIS HTTP workflow only.
            'new_services.*.receive_now_amount' => ['required', 'regex:'.self::MONEY_REGEX, 'gt:0'],
            'new_services.*.grade_group' => ['nullable', 'string', 'max:50'],
            'new_services.*.payment_period' => ['nullable', 'string', 'max:50'],
            'new_services.*.billing_strategy' => ['nullable', 'string', 'in:once,calendar'],
            'new_services.*.transport_area' => ['nullable', 'string', 'max:150'],
            'new_services.*.transport_route_id' => ['nullable', 'integer'],
            'new_services.*.meal_plan_id' => ['nullable', 'integer'],
            'new_services.*.food_duration_mode' => ['nullable', 'string', Rule::in(['day', 'school_week', 'teaching_days', 'custom_range'])],
            'new_services.*.food_date' => ['nullable', 'date_format:Y-m-d'],
            'new_services.*.food_week_start' => ['nullable', 'date_format:Y-m-d'],
            'new_services.*.food_start_date' => ['nullable', 'date_format:Y-m-d'],
            'new_services.*.food_day_count' => ['nullable', 'integer', 'min:1'],
            'new_services.*.food_range_start' => ['nullable', 'date_format:Y-m-d'],
            'new_services.*.food_range_end' => ['nullable', 'date_format:Y-m-d'],
            'new_services.*.uniform_items' => ['nullable', 'array'],
            'new_services.*.uniform_items.*.uniform_product_id' => ['required', 'integer'],
            'new_services.*.uniform_items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],

            'annual_registration' => ['nullable', 'array'],
            'annual_registration.enrollment_mode_id' => ['required_with:annual_registration', 'integer'],
            'annual_registration.stage_id' => ['required_with:annual_registration', 'integer'],
            'annual_registration.grade_id' => ['required_with:annual_registration', 'integer'],
            'annual_registration.class_id' => ['required_with:annual_registration', 'integer'],
            'annual_registration.registration_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'new_services.*.receive_now_amount.gt' => 'Укажите сумму, полученную от родителя по этой услуге.',
            'payment_method.in' => 'Выбран недопустимый способ оплаты.',
        ];
    }
}

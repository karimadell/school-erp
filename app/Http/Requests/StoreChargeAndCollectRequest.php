<?php

namespace App\Http\Requests;

use App\Models\CashAccount;
use App\Models\FeePrice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Phase 2 — cashier charge & collect. Validates a single-service charge plus
 * an optional collection, and shapes the payload into the canonical issuance
 * format consumed by InvoiceIssuanceService::issue().
 */
class StoreChargeAndCollectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage invoices') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $student = $this->route('student');
        $feeId = (int) $this->input('fee_id');

        $this->merge([
            'student_id' => $student?->id,
            'pricing_date' => $this->input('pricing_date', now()->toDateString()),
            'payment_type' => 'one_time',
            'collect_amount' => $this->input('collect_amount', '0'),
            'notes' => $this->input('notes'),
            'items' => [[
                'fee_id' => $feeId,
                'quantity' => (int) $this->input('quantity', 1),
                'grade_group' => $this->input('grade_group'),
                'payment_period' => $this->input('payment_period'),
                'first_last_month' => $this->input('first_last_month') === '1',
                'size' => $this->input('size'),
                'item' => $this->input('item'),
                'option_type' => $this->input('option_type'),
                'option_value' => $this->input('option_value'),
            ]],
        ]);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'fee_id' => ['required', 'integer', 'exists:fees,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'due_date' => ['required', 'date'],
            'pricing_date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.fee_id' => ['required', 'integer'],
            'items.*.grade_group' => ['nullable', Rule::in(FeePrice::GRADE_GROUPS)],
            'items.*.payment_period' => ['nullable', 'string', 'max:50'],
            'items.*.first_last_month' => ['boolean'],
            'items.*.size' => ['nullable', 'string', 'max:100'],
            'items.*.item' => ['nullable', 'string', 'max:100'],
            'items.*.option_type' => ['nullable', 'string', 'max:100'],
            'items.*.option_value' => ['nullable', 'string', 'max:255'],

            'collect_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'payment_method' => ['nullable', 'in:cash,bank,card,transfer,instapay'],
            'cash_account_id' => ['nullable', 'integer', 'exists:cash_accounts,id'],
            'idempotency_key' => ['required', 'uuid'],

            'payment_type' => ['required', 'in:one_time'],
            'notes' => ['nullable', 'string', 'max:1000'],

            // Tariff-only: never accept a client-supplied price/amount.
            'unit_price' => ['prohibited'],
            'amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'paid_amount' => ['prohibited'],
            'remaining_amount' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Collecting money requires a method and, for methods with no
            // canonical account mapping (card/transfer), a cash account —
            // cash/bank/instapay resolve their own canonical account
            // server-side and never consult this field.
            if (bccomp((string) ($this->input('collect_amount') ?? '0'), '0', 2) > 0) {
                if (! $this->filled('payment_method')) {
                    $validator->errors()->add('payment_method', 'Выберите способ оплаты.');
                }
                $resolvesCanonically = $this->filled('payment_method')
                    && CashAccount::canonicalRoleForMethod((string) $this->input('payment_method')) !== null;
                if (! $resolvesCanonically && ! $this->filled('cash_account_id')) {
                    $validator->errors()->add('cash_account_id', 'Выберите кассу.');
                }
            }
        });
    }
}

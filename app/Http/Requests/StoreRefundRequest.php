<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('refund payments') ?? false;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
            // Finance V2, Phase 1D — optional per-line amount, keyed by
            // payment_allocation_id. Only meaningful, and only offered by
            // the UI, when the payment being refunded has more than one
            // PaymentAllocation. Shape-only validation here; every
            // financial rule (ownership, sum, per-allocation cap,
            // non-refundable items) is re-validated authoritatively inside
            // InvoiceRefundService — this is advisory only, never trusted
            // as-is (browser-supplied remaining balances are never
            // authoritative).
            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'decimal:0,2', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Укажите сумму возврата.',
            'amount.decimal' => 'Сумма должна содержать не более двух знаков после запятой.',
            'amount.gt' => 'Сумма возврата должна быть больше нуля.',
            'reason.required' => 'Укажите причину возврата.',
            'idempotency_key.uuid' => 'Не удалось подтвердить уникальность возврата. Обновите страницу.',
        ];
    }
}

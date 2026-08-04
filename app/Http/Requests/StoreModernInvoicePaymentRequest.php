<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreModernInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage invoices') ?? false;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'invoice_installment_id' => ['nullable', 'integer', 'exists:invoice_installments,id'],
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'payment_method' => ['required', 'in:cash,card,bank'],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Укажите сумму платежа.',
            'amount.decimal' => 'Сумма должна содержать не более двух знаков после запятой.',
            'amount.gt' => 'Сумма платежа должна быть больше нуля.',
            'invoice_installment_id.exists' => 'Выбранный этап рассрочки не найден.',
            'cash_account_id.required' => 'Выберите кассу.',
            'cash_account_id.exists' => 'Выбранная касса не найдена.',
            'payment_method.required' => 'Выберите способ оплаты.',
            'payment_method.in' => 'Выбран недопустимый способ оплаты.',
            'idempotency_key.uuid' => 'Не удалось подтвердить уникальность платежа. Обновите страницу.',
        ];
    }
}

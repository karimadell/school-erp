<?php

namespace App\Http\Requests;

use App\Models\CashAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware already enforces 'open cash sessions'.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cash_account_id' => [
                'required',
                Rule::exists('cash_accounts', 'id')->where(fn ($query) => $query
                    ->where('type', CashAccount::TYPE_CASH)
                    ->where('is_active', true)),
            ],
            'open_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cash_account_id.required' => 'Выберите кассу.',
            'cash_account_id.exists' => 'Выберите активную наличную кассу.',
        ];
    }
}

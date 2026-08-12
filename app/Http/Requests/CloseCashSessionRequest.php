<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware already enforces 'close cash sessions'. The extra
        // 'close cash sessions with variance' check is applied in the service,
        // since whether a variance exists is only known after counting.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'closing_counted' => ['required', 'numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'close_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'closing_counted.required' => 'Укажите фактический остаток.',
            'closing_counted.numeric' => 'Укажите корректную сумму.',
            'closing_counted.regex' => 'Укажите корректную сумму.',
            'closing_counted.min' => 'Фактический остаток не может быть отрицательным.',
        ];
    }
}

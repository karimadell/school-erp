<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnrollmentModeRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isActive() && $this->user()->can('manage academic years'); }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim((string) $this->input('code')))),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name_ru' => ['required','string','max:255'],
            'short_name_ru' => ['nullable','string','max:100'],
            'code' => ['required','string','max:100','regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',Rule::unique('enrollment_modes','code')],
            'display_order' => ['required','integer','min:0'],
            'is_active' => ['boolean'],
            'description' => ['nullable','string','max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['name_ru.required'=>'Укажите название на русском языке.','code.required'=>'Укажите код формы обучения.','code.unique'=>'Форма обучения с таким кодом уже существует.','code.regex'=>'Код может содержать строчные латинские буквы, цифры и знак подчёркивания.','display_order.required'=>'Укажите порядок отображения.','display_order.min'=>'Порядок отображения не может быть отрицательным.'];
    }
}

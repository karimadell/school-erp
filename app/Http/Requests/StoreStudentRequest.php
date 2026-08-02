<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['last_name_ru', 'first_name_ru', 'patronymic_ru'] as $field) {
            $this->merge([$field => Student::normalizeRussianNamePart($this->input($field))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'last_name_ru' => ['required', 'string', 'max:100'],
            'first_name_ru' => ['required', 'string', 'max:100'],
            'patronymic_ru' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'unique:students,email'],
            'phone' => ['nullable', 'regex:/^01[0-2,5]{1}[0-9]{8}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'last_name_ru.required' => 'Укажите фамилию ученика.',
            'first_name_ru.required' => 'Укажите имя ученика.',
            'email.email' => 'Укажите корректный адрес электронной почты.',
            'email.unique' => 'Этот адрес электронной почты уже используется.',
            'phone.regex' => 'Укажите корректный египетский номер телефона.',
        ];
    }
}

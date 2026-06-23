<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['nullable', 'email', 'unique:students,email'],
            'phone' => ['nullable', 'regex:/^01[0-2,5]{1}[0-9]{8}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Student name is required',
            'name.min'      => 'Student name must be at least 3 characters',
            'email.email'   => 'Invalid email address',
            'email.unique'  => 'Email already exists',
            'phone.regex'   => 'Invalid Egyptian phone number',
        ];
    }
}
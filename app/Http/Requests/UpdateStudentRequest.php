<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $studentId = $this->route('id');

        return [
            'name'  => ['sometimes', 'string', 'min:3', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'unique:students,email,' . $studentId],
            'phone' => ['sometimes', 'nullable', 'regex:/^01[0-2,5]{1}[0-9]{8}$/'],
        ];
    }
}
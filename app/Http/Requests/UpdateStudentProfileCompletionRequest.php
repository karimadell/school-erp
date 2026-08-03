<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentProfileCompletionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isActive() && $this->user()->can('manage students'); }

    protected function prepareForValidation(): void
    {
        foreach (['last_name_ru', 'first_name_ru', 'patronymic_ru'] as $field) {
            $this->merge([$field => Student::normalizeRussianNamePart($this->input($field))]);
        }
    }

    public function rules(): array
    {
        return [
            'last_name_ru' => ['required', 'string', 'max:100'], 'first_name_ru' => ['required', 'string', 'max:100'],
            'patronymic_ru' => ['nullable', 'string', 'max:100'], 'gender' => ['required', 'in:male,female'],
            'birth_date' => ['required', 'date', 'before:today'], 'nationality' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:1000'], 'phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-()]{7,20}$/'],
            'email' => ['nullable', 'email', 'max:255'], 'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'father_name' => ['nullable', 'string', 'max:255'], 'father_phone' => ['nullable', 'string', 'max:30'],
            'father_email' => ['nullable', 'email', 'max:255'], 'father_identity' => ['nullable', 'string', 'max:100'],
            'mother_name' => ['nullable', 'string', 'max:255'], 'mother_phone' => ['nullable', 'string', 'max:30'],
            'mother_email' => ['nullable', 'email', 'max:255'], 'mother_identity' => ['nullable', 'string', 'max:100'],
            'emergency_name' => ['nullable', 'string', 'max:255'], 'emergency_relationship' => ['nullable', 'string', 'max:100'],
            'emergency_phone' => ['nullable', 'string', 'max:30'], 'medical_notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['last_name_ru.required'=>'Укажите фамилию ученика.','first_name_ru.required'=>'Укажите имя ученика.','gender.required'=>'Укажите пол ученика.','birth_date.required'=>'Укажите дату рождения.','nationality.required'=>'Укажите гражданство.','address.required'=>'Укажите адрес проживания.','phone.required'=>'Укажите телефон.','photo.max'=>'Размер фотографии не должен превышать 2 МБ.','photo.mimes'=>'Фотография должна быть в формате JPG, JPEG, PNG или WEBP.','status.prohibited'=>'Статус регистрации нельзя изменять вручную.'];
    }
}

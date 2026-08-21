<?php

namespace App\Http\Requests;

use App\Models\Student;
use App\Models\StudentRepresentative;
use App\Rules\ValidInn;
use App\Rules\ValidSnils;
use App\Support\PersonalIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentProfileCompletionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isActive() && $this->user()->can('manage students'); }

    protected function prepareForValidation(): void
    {
        foreach (['last_name_ru', 'first_name_ru', 'patronymic_ru'] as $field) {
            $this->merge([$field => Student::normalizeRussianNamePart($this->input($field))]);
        }
        $this->merge(['snils' => PersonalIdentifier::normalize($this->input('snils')), 'inn' => PersonalIdentifier::normalize($this->input('inn')), 'citizenship_code' => $this->filled('citizenship_code') ? strtoupper($this->input('citizenship_code')) : null]);
        $representatives = collect($this->input('representatives', []))->map(function ($representative) {
            $representative['snils'] = PersonalIdentifier::normalize($representative['snils'] ?? null);
            $representative['inn'] = PersonalIdentifier::normalize($representative['inn'] ?? null);

            return $representative;
        })->all();
        $this->merge(['representatives' => $representatives]);
    }

    public function rules(): array
    {
        return [
            'last_name_ru' => ['required', 'string', 'max:100'], 'first_name_ru' => ['required', 'string', 'max:100'],
            'patronymic_ru' => ['nullable', 'string', 'max:100'], 'gender' => ['required', 'in:male,female'],
            'birth_date' => ['required', 'date', 'before:today'], 'nationality' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'required_without:residential_address', 'string', 'max:2000'], 'phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-()]{7,20}$/'],
            'email' => ['nullable', 'email', 'max:255'], 'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'birth_place' => ['nullable', 'string', 'max:255'], 'citizenship_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'residential_address' => ['nullable', 'required_without:address', 'string', 'max:2000'], 'registration_address' => ['nullable', 'string', 'max:2000'],
            'snils' => ['nullable', new ValidSnils], 'inn' => ['nullable', new ValidInn],
            'father_name' => ['nullable', 'string', 'max:255'], 'father_phone' => ['nullable', 'string', 'max:30'],
            'father_email' => ['nullable', 'email', 'max:255'], 'father_identity' => ['nullable', 'string', 'max:100'],
            'mother_name' => ['nullable', 'string', 'max:255'], 'mother_phone' => ['nullable', 'string', 'max:30'],
            'mother_email' => ['nullable', 'email', 'max:255'], 'mother_identity' => ['nullable', 'string', 'max:100'],
            'emergency_name' => ['nullable', 'string', 'max:255'], 'emergency_relationship' => ['nullable', 'string', 'max:100'],
            'emergency_phone' => ['nullable', 'string', 'max:30'], 'medical_notes' => ['nullable', 'string', 'max:2000'],
            'representatives' => ['nullable', 'array', 'max:10'],
            'representatives.*.id' => ['nullable', 'integer', Rule::exists('student_representatives', 'id')->where('student_id', $this->route('student')->id)],
            'representatives.*.relationship_type' => ['required_with:representatives.*.full_name', Rule::in(StudentRepresentative::RELATIONSHIPS)],
            'representatives.*.full_name' => ['nullable', 'string', 'max:255'], 'representatives.*.phone' => ['nullable', 'string', 'max:50'],
            'representatives.*.email' => ['nullable', 'email', 'max:255'], 'representatives.*.citizenship_code' => ['nullable', 'string', 'size:2'],
            'representatives.*.residential_address' => ['nullable', 'string', 'max:2000'], 'representatives.*.snils' => ['nullable', new ValidSnils],
            'representatives.*.inn' => ['nullable', new ValidInn], 'representatives.*.notes' => ['nullable', 'string', 'max:2000'],
            'representatives.*.is_legal_representative' => ['nullable', 'boolean'], 'representatives.*.is_primary_contact' => ['nullable', 'boolean'],
            'representatives.*.has_guardianship_authority' => ['nullable', 'boolean'],
            'emergency_contacts' => ['nullable', 'array', 'max:10'], 'emergency_contacts.*.id' => ['nullable', 'integer', Rule::exists('student_emergency_contacts', 'id')->where('student_id', $this->route('student')->id)],
            'emergency_contacts.*.full_name' => ['nullable', 'string', 'max:255'], 'emergency_contacts.*.relationship' => ['nullable', 'string', 'max:100'],
            'emergency_contacts.*.phone' => ['required_with:emergency_contacts.*.full_name', 'nullable', 'string', 'max:50'], 'emergency_contacts.*.email' => ['nullable', 'email', 'max:255'],
            'emergency_contacts.*.priority' => ['nullable', 'integer', 'min:1', 'max:100'], 'emergency_contacts.*.notes' => ['nullable', 'string', 'max:2000'],
            'admission_context' => ['nullable', Rule::in(['initial', 'transfer', 'continuation', 're_enrollment', 'other'])],
            'previous_school_name' => ['nullable', 'string', 'max:255'], 'previous_school_country_code' => ['nullable', 'string', 'size:2'],
            'previous_grade' => ['nullable', 'string', 'max:100'], 'previous_class' => ['nullable', 'string', 'max:100'], 'previous_education_notes' => ['nullable', 'string', 'max:2000'],
            'has_ovz' => ['nullable', 'boolean'], 'has_disability' => ['nullable', 'boolean'], 'requires_adapted_program' => ['nullable', 'boolean'],
            'requires_special_conditions' => ['nullable', 'boolean'], 'special_conditions' => ['nullable', 'string', 'max:5000'],
            'educational_needs_notes' => ['nullable', 'string', 'max:5000'], 'consent_status' => ['nullable', 'string', 'max:100'], 'consent_received_at' => ['nullable', 'date'],
            'status' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['last_name_ru.required'=>'Укажите фамилию ученика.','first_name_ru.required'=>'Укажите имя ученика.','gender.required'=>'Укажите пол ученика.','birth_date.required'=>'Укажите дату рождения.','nationality.required'=>'Укажите гражданство.','address.required'=>'Укажите адрес проживания.','phone.required'=>'Укажите телефон.','photo.max'=>'Размер фотографии не должен превышать 2 МБ.','photo.mimes'=>'Фотография должна быть в формате JPG, JPEG, PNG или WEBP.','status.prohibited'=>'Статус регистрации нельзя изменять вручную.'];
    }
}

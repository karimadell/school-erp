<?php

namespace App\Http\Requests;

use App\Models\SchoolSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSchoolSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', SchoolSetting::current()) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'print_date_enabled' => $this->boolean('print_date_enabled'),
            'page_numbers_enabled' => $this->boolean('page_numbers_enabled'),
        ]);
    }

    public function rules(): array
    {
        return [
            'school_name' => ['required', 'string', 'max:255'],
            'short_name' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'timezone' => ['required', 'timezone'],
            'language' => ['required', Rule::in(['ru'])],
            'phone_1' => ['required', 'string', 'max:50'],
            'phone_2' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            // Keep this at or below the deployed PHP upload_max_filesize
            // (currently 2 MB). A larger Laravel limit lets PHP reject the
            // request first and produces only the opaque "failed to upload"
            // message instead of useful validation feedback.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'printing_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'stamp' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'director_signature' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'header_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'footer_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'print_date_enabled' => ['required', 'boolean'],
            'page_numbers_enabled' => ['required', 'boolean'],
            'currency' => ['required', Rule::in(['EGP'])],
            'currency_symbol' => ['required', 'string', 'max:10'],
            'decimal_places' => ['required', 'integer', 'between:0,4'],
            'amount_format' => ['required', Rule::in(['1 234.56', '1,234.56', '1.234,56'])],
            'default_academic_year_id' => ['nullable', 'exists:academic_years,id'],
            'school_year_start' => ['nullable', 'date'],
            'school_year_end' => ['nullable', 'date', 'after_or_equal:school_year_start'],
        ];
    }

    public function messages(): array
    {
        return [
            'school_name.required' => 'Укажите название школы.',
            'short_name.required' => 'Укажите краткое название школы.',
            'phone_1.required' => 'Укажите основной телефон школы.',
            'email.required' => 'Укажите электронную почту школы.',
            'email.email' => 'Укажите корректную электронную почту.',
            'logo.image' => 'Логотип должен быть изображением.',
            'logo.uploaded' => 'Не удалось загрузить логотип. Максимальный размер файла — 2 МБ.',
            'logo.max' => 'Размер логотипа не должен превышать 2 МБ.',
            'logo.mimes' => 'Логотип должен быть в формате PNG, JPG, JPEG или WEBP.',
            'school_year_end.after_or_equal' => 'Дата окончания учебного года не может быть раньше даты начала.',
        ];
    }
}

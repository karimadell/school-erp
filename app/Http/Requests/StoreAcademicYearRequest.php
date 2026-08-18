<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors the field set enforced by the existing Filament
 * AcademicYearForm schema (app/Filament/Resources/AcademicYears/Schemas/
 * AcademicYearForm.php) — name/start_date/end_date/is_active, all
 * required — so the classic dashboard and the Filament resource validate
 * an AcademicYear identically. Authorization is handled by
 * AcademicYearController::authorizeResource(), not here.
 */
class StoreAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['end_date.after' => __('academic_years.validation.end_after_start')];
    }
}

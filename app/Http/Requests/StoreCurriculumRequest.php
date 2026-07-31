<?php

namespace App\Http\Requests;

use App\Models\Curriculum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the field set and uniqueness rule enforced by the existing
 * Filament CurriculumForm schema (app/Filament/Resources/Curricula/
 * Schemas/CurriculumForm.php) — one Curriculum row per
 * (academic_year_id, grade_id, subject_id), matching the DB's
 * curricula_unique constraint. Authorization is handled by
 * CurriculumController::authorizeResource(), not here.
 */
class StoreCurriculumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => [
                'required',
                'exists:academic_years,id',
                Rule::unique('curricula')->where(fn ($query) => $query
                    ->where('grade_id', $this->input('grade_id'))
                    ->where('subject_id', $this->input('subject_id'))),
            ],
            'grade_id' => ['required', 'exists:grades,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'weekly_hours' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in([
                Curriculum::TYPE_MANDATORY,
                Curriculum::TYPE_ELECTIVE,
                Curriculum::TYPE_OPTIONAL_ENRICHMENT,
            ])],
            'assessment_type' => ['required', Rule::in([
                Curriculum::ASSESSMENT_GRADE,
                Curriculum::ASSESSMENT_PASS_FAIL,
                Curriculum::ASSESSMENT_UNGRADED,
            ])],
            'report_order' => ['nullable', 'integer', 'between:1,999'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'academic_year_id.unique' => __('curriculum.duplicate'),
        ];
    }
}

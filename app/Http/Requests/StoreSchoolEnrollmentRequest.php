<?php

namespace App\Http\Requests;

use App\Models\AcademicYear;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSchoolEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage students') === true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['student_name_ru', 'student_name_en', 'student_name_ar'] as $field) {
            $this->merge([$field => Student::normalizeRussianNamePart($this->input($field))]);
        }
    }

    public function rules(): array
    {
        return [
            'student_name_ru' => ['required', 'string', 'max:255'],
            'student_name_en' => ['nullable', 'string', 'max:255'],
            'student_name_ar' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'in:male,female'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'identity_document' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'father_phone' => ['nullable', 'string', 'max:50'],
            'father_email' => ['nullable', 'email', 'max:255'],
            'father_passport' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'mother_phone' => ['nullable', 'string', 'max:50'],
            'mother_email' => ['nullable', 'email', 'max:255'],
            'mother_passport' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:500'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'stage_id' => ['required', 'integer', 'exists:stages,id'],
            'grade_id' => ['required', 'integer', 'exists:grades,id'],
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'fee_price_ids' => ['required', 'array', 'min:1'],
            'fee_price_ids.*' => ['required', 'integer', 'distinct', 'exists:fee_prices,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $year = AcademicYear::find($this->integer('academic_year_id'));
            if ($year && ! $year->is_active) {
                $validator->errors()->add('academic_year_id', 'Выберите активный учебный год.');
            }

            $stage = Stage::find($this->integer('stage_id'));
            if ($stage && ! $stage->is_active) {
                $validator->errors()->add('stage_id', 'Выбранная ступень неактивна.');
            }
            $grade = Grade::find($this->integer('grade_id'));
            if ($grade && $grade->stage_id !== $this->integer('stage_id')) {
                $validator->errors()->add('grade_id', 'Класс не относится к выбранной ступени.');
            }
            $class = SchoolClass::find($this->integer('class_id'));
            if ($class && ($class->grade_id !== $this->integer('grade_id') || ! $class->is_active)) {
                $validator->errors()->add('class_id', 'Выберите активную учебную группу выбранного класса.');
            }

            if ($year) {
                $invalidTariff = FeePrice::query()
                    ->whereIn('id', $this->input('fee_price_ids', []))
                    ->where(function ($query) use ($year) {
                        $query->where('academic_year_id', '!=', $year->id)
                            ->orWhere('currency', '!=', 'EGP')
                            ->orWhere('is_active', false)
                            ->orWhereDate('start_date', '>', $year->end_date)
                            ->orWhere(fn ($query) => $query->whereNotNull('end_date')->whereDate('end_date', '<', $year->start_date));
                    })->exists();
                if ($invalidTariff) {
                    $validator->errors()->add('fee_price_ids', 'Одна из выбранных услуг недоступна для этого учебного года.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'student_name_ru.required' => 'Укажите полное имя ученика на русском языке.',
            'academic_year_id.required' => 'Выберите учебный год.',
            'stage_id.required' => 'Выберите ступень.',
            'grade_id.required' => 'Выберите класс.',
            'class_id.required' => 'Выберите учебную группу.',
            'fee_price_ids.required' => 'Выберите хотя бы одну услугу.',
            'fee_price_ids.min' => 'Выберите хотя бы одну услугу.',
            'photo.max' => 'Размер фотографии не должен превышать 2 МБ.',
            'photo.image' => 'Фотография должна быть изображением.',
        ];
    }
}

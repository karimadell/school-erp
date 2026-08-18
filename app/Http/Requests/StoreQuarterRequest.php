<?php

namespace App\Http\Requests;

use App\Models\Quarter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreQuarterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'order' => ['required', 'integer', 'min:1', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $year = $this->route('academicYear');
            if ($this->date('start_date')->lt($year->start_date)
                || $this->date('end_date')->gt($year->end_date)) {
                $validator->errors()->add('start_date', __('quarters.validation.within_year'));
            }

            if (Quarter::query()
                ->where('academic_year_id', $year->id)
                ->whereDate('start_date', '<=', $this->date('end_date'))
                ->whereDate('end_date', '>=', $this->date('start_date'))
                ->exists()) {
                $validator->errors()->add('start_date', __('quarters.validation.overlap'));
            }
        }];
    }

    public function messages(): array
    {
        return ['end_date.after' => __('quarters.validation.end_after_start')];
    }
}

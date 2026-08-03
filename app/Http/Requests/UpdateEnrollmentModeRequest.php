<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateEnrollmentModeRequest extends StoreEnrollmentModeRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['code'] = ['required','string','max:100','regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',Rule::unique('enrollment_modes','code')->ignore($this->route('enrollmentMode'))];
        return $rules;
    }
}

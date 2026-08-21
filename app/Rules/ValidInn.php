<?php

namespace App\Rules;

use App\Support\PersonalIdentifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidInn implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! PersonalIdentifier::validInn((string) $value)) {
            $fail(__('student_registration.validation.inn'));
        }
    }
}

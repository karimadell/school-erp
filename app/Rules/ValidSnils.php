<?php

namespace App\Rules;

use App\Support\PersonalIdentifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidSnils implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! PersonalIdentifier::validSnils((string) $value)) {
            $fail(__('student_registration.validation.snils'));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A custom rule with nothing a schema can state, and no #[RuleSchema] to say so. Only ever reflected. */
final class NoProfanity implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && str_contains(strtolower($value), 'darn')) {
            $fail('The :attribute may not contain profanity.');
        }
    }
}

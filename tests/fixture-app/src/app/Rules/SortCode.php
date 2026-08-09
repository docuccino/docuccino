<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Docuccino\Attributes\RuleSchema;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An idiomatic custom rule documented once by its class-level `#[RuleSchema]`, so every field using it
 * is documented on the real engine. Only ever reflected — the attribute is read, `validate()` never runs.
 */
#[RuleSchema(
    type: 'string',
    pattern: '[0-9]{2}-[0-9]{2}-[0-9]{2}',
    min: 8,
    max: 8,
    description: 'A UK sort code, hyphenated.',
    example: '20-15-55',
)]
final class SortCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\d{2}-\d{2}-\d{2}$/', $value) !== 1) {
            $fail('The :attribute is not a valid sort code.');
        }
    }
}

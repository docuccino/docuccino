<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Docuccino\Attributes\RuleSchema;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A size-only custom rule — it caps the encoded size of a JSON blob and says nothing about its type,
 * which is what a real `rules()` override puts alongside `array` for a metadata column. Only ever
 * reflected — the attribute is read, `validate()` never runs.
 */
#[RuleSchema(max: 65536, description: 'At most 64 KiB once encoded.')]
final class MaxJsonByteSize implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (strlen((string) json_encode($value)) > 65536) {
            $fail('The :attribute is larger than 64 KiB once encoded.');
        }
    }
}

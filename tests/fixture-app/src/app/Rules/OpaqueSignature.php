<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** The unannotated sibling: genuinely opaque, so its field stays diagnosed rather than guessed at. */
final class OpaqueSignature implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) < 32) {
            $fail('The :attribute signature is invalid.');
        }
    }
}

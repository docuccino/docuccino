<?php

declare(strict_types=1);

namespace App\Exceptions\Validation;

use Illuminate\Validation\ValidationException;

/** A validation failure that reports its messages as one flat list rather than by field. */
final class FlatValidationException extends ValidationException
{
    /** @return list<string> */
    public function errors(): array
    {
        return $this->validator->errors()->all();
    }
}

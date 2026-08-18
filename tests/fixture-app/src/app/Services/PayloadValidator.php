<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\OutOfStockException;

/**
 * A project class with its own `validate()` — the name the throw registry keys `ValidationException`
 * (422) on. Nothing here is a Laravel validator: the method throws the application's own exception,
 * and the engine can read that body, so the registry must stay out of the way.
 */
class PayloadValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        if ($payload === []) {
            throw new OutOfStockException('nothing to validate');
        }

        return $payload;
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\TokenRefusal;

/**
 * A refused rotation, built only through its named constructors.
 */
final class RotationRefused extends Refusal
{
    private function __construct(TokenRefusal $reason, string $detail)
    {
        parent::__construct($reason, $detail);
    }

    public static function expired(): self
    {
        return new self(TokenRefusal::Expired, 'The token has expired.');
    }

    public static function because(TokenRefusal $reason): self
    {
        return new self($reason, 'The token was refused.');
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

final class PortalThrottledException extends PortalException implements HasRetryWindow
{
    public function __construct(private readonly int $seconds = 60)
    {
        parent::__construct(429, 'Too many submissions.');
    }

    public function retryAfter(): int
    {
        return $this->seconds;
    }
}

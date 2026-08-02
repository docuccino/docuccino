<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

/**
 * A parsed `throttle` middleware declaration. Two shapes: a numeric limit (`throttle:60,1` →
 * {@see $maxAttempts}/{@see $decayMinutes} known) whose numbers document the rate headers, or a
 * named limiter (`throttle:api` → {@see $name}) whose limit is defined by a `RateLimiter::for`
 * closure and cannot be recovered without executing user code — the 429 is still documented, but
 * without numbers.
 */
final readonly class ThrottleLimit
{
    public function __construct(
        public ?int $maxAttempts = null,
        public ?int $decayMinutes = null,
        public ?string $name = null,
    ) {}

    public function isNamed(): bool
    {
        return $this->name !== null;
    }
}

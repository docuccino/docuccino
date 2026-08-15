<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

/**
 * A parsed `throttle` declaration, either numeric (`throttle:60,1`) or named (`throttle:api`, its limit
 * defined by a `RateLimiter::for` closure). A named limiter the engine manages to fold becomes numeric and
 * carries {@see $decaySeconds}, since per-second/hour/day windows don't fit the middleware's whole-minute
 * {@see $decayMinutes}.
 *
 * The recovered numbers no longer reach the document — the 429 is value-free for every route, see
 * {@see RateLimitResponse} — so only {@see isNamed} and whether a fold succeeded still have consequences.
 */
final readonly class ThrottleLimit
{
    public function __construct(
        public ?int $maxAttempts = null,
        public ?float $decayMinutes = null,
        public ?string $name = null,
        public ?int $guestMaxAttempts = null,
        public ?int $decaySeconds = null,
    ) {}

    public function isNamed(): bool
    {
        return $this->name !== null;
    }
}

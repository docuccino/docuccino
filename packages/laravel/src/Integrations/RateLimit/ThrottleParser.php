<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

/**
 * Parses a route's `throttle` middleware string into a {@see ThrottleLimit} (design §Phase 4 — rate
 * limiting): `throttle:60,1` → numeric limit (60 attempts / 1 minute), `throttle:60` → 60 / default
 * 1 minute, `throttle:api` → named limiter `api`. Anything that is not a throttle declaration
 * returns null.
 */
final class ThrottleParser
{
    public function parse(string $middleware): ?ThrottleLimit
    {
        if ($middleware !== 'throttle' && ! str_starts_with($middleware, 'throttle:')) {
            return null;
        }

        $parameters = $middleware === 'throttle' ? '' : substr($middleware, strlen('throttle:'));
        if ($parameters === '') {
            // A bare `throttle` with no parameters: a limit is still enforced, but its numbers live
            // in a named limiter we cannot statically read — document it as a named-style limit.
            return new ThrottleLimit(name: 'default');
        }

        $parts = explode(',', $parameters);
        $first = trim($parts[0]);

        if (! ctype_digit($first)) {
            return new ThrottleLimit(name: $first);
        }

        $decay = isset($parts[1]) && ctype_digit(trim($parts[1])) ? (int) trim($parts[1]) : 1;

        return new ThrottleLimit(maxAttempts: (int) $first, decayMinutes: $decay);
    }
}

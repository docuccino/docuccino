<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Phase-4b real-engine fixture: a "rate limited" (429) exception the Problem-Details renderer maps by
 * returning a `new JsonResponse(...)` DIRECTLY from the match arm — the zero-hop constructor-fold path.
 */
class RateLimitedException extends RuntimeException
{
}

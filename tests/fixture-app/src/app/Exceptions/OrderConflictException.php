<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Phase-4b real-engine fixture: a domain "order conflict" (409) exception the Problem-Details renderer
 * maps through a ONE-hop helper (`__invoke` arm → `ProblemResponse::make(...)` directly).
 */
class OrderConflictException extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Phase-4b real-engine fixture: a domain "missing" (404) exception the Problem-Details renderer maps
 * through a TWO-hop enum path (`__invoke` arm → `renderProblem(InvoiceProblem::NotFound, …)` →
 * `ProblemResponse::fromProblem(…)`), so the enum-case accessor is re-homed one hop then folded.
 */
class InvoiceMissingException extends RuntimeException
{
}

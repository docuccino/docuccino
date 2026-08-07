<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Phase-4b real-engine fixture: a domain "forbidden" (403) exception the Problem-Details renderer maps
 * through the enum-driven `ProblemResponse::fromProblem(InvoiceProblem::Forbidden, …)` helper — the
 * flagship enum-accessor fold (per-case type URI, status and title `const`s).
 */
class InvoiceForbiddenException extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Phase-4b real-engine fixture: a domain "invoice not found" (404) exception the Problem-Details
 * renderer maps through a two-hop helper chain (`__invoke` → `renderNotFound()` →
 * `ProblemResponse::make(...)`). Generic invoices/orders naming — NOT a copy of any real app.
 */
class InvoiceNotFoundException extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Phase-4b real-engine fixture: an exception the renderer maps through the VENDOR-enum helper
 * (`ProblemResponse::fromOperator(FilterOperator::EQUAL)`) — proving a vendor enum's `->value`/`->name`
 * fold (reflection is vendor-safe) while its instance METHOD is never analysed (project-only boundary).
 */
class InvoiceVendorEnumException extends RuntimeException
{
}

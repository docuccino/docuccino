<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Phase-4b real-engine fixture: a type the Problem-Details renderer deliberately DELEGATES to the
 * framework via a per-type `$e instanceof … => null` match arm — proving a null arm reads as
 * "delegate to the next tier", not as a fold failure.
 */
class InvoiceDelegatedException extends RuntimeException
{
}

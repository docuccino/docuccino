<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Custom domain exception thrown by OrderService. Used by Spike C to prove
 * Layer 3 descent surfaces project-code exceptions that never reach the
 * controller's own throw points.
 */
class OutOfStockException extends Exception
{
}

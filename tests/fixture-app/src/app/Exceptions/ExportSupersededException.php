<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The status parameter reused after it was forwarded. What the response carries is what the parent was
 * handed — the 409 the default names — and a read that folds in the body's END scope answers the LAST value
 * the variable held instead, a status nothing was ever built with. No status at all is the honest answer:
 * position is not read, so a write anywhere in the body retires the default.
 */
final class ExportSupersededException extends HttpException
{
    public int $reported = 0;

    private function __construct(int $statusCode = 409)
    {
        parent::__construct($statusCode, 'The export was superseded.');

        // What the class records for itself, which is not what the response is.
        $statusCode = 500;
        $this->reported = $statusCode;
    }

    public static function superseded(): self
    {
        return new self;
    }
}

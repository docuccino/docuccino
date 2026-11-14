<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ExportFault;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An export failure answered with its fault's backing value as the status.
 */
final class ExportFaultException extends HttpException
{
    public function __construct(public readonly ExportFault $fault, string $message = '')
    {
        parent::__construct($fault->value, $message);
    }
}

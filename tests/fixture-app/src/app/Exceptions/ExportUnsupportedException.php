<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The factory idiom with no constructor of its own: the framework's runs, and the named factory builds the
 * exception with its status before decorating it with what the caller passed.
 */
final class ExportUnsupportedException extends HttpException
{
    public ?string $format = null;

    public static function forFormat(?string $format = null): self
    {
        $exception = new self(Response::HTTP_UNPROCESSABLE_ENTITY, 'The export format is not supported.');
        $exception->format = $format;

        return $exception;
    }
}

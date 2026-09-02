<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\ProbeStatuses;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A status pinned through a constant the application declares somewhere else entirely, which is where an
 * application keeps the numbers it uses in more than one place.
 */
final class ExportArchivedException extends HttpException
{
    public function __construct()
    {
        parent::__construct(ProbeStatuses::ARCHIVED, 'The export was archived.');
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\ProbeStatuses;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A constant reaching the parent that is no HTTP status: the constants class holds the retry count beside
 * the statuses, and the wrong one was picked. The response key `"3"` is one no client can read, so the
 * honest answer is no status at all.
 */
final class ExportRelayedException extends HttpException
{
    public function __construct()
    {
        parent::__construct(ProbeStatuses::RETRIES, 'The export was relayed.');
    }
}

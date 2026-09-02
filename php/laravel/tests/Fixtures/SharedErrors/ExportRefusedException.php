<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * An export the service refused. Both the reason and the instant it happened are properties of the
 * failure rather than of the endpoint, so no render arm can write either of them out.
 */
final class ExportRefusedException extends RuntimeException
{
    public function __construct(
        private readonly ExportFailure $reason,
        private readonly CarbonImmutable $failedAt,
    ) {
        parent::__construct('The export was refused.');
    }

    public function reason(): ExportFailure
    {
        return $this->reason;
    }

    public function failedAt(): CarbonImmutable
    {
        return $this->failedAt;
    }
}

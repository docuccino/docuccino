<?php

declare(strict_types=1);

namespace App\Problems;

/** The id of the request a problem was raised in, when it has one. */
trait CarriesTrace
{
    public string $traceId;

    protected function trace(?string $traceId): void
    {
        if ($traceId !== null) {
            $this->traceId = $traceId;
        }
    }
}

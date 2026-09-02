<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Exceptions\ExportOfflineException;
use App\Exceptions\ProbeStaleException;

/**
 * A guard clause an application shares across actions by trait. The `throw` is written here and surfaces at
 * the using class as a DECLARED exception, so the analysed method's throw point is the call rather than a
 * construction.
 */
trait GuardsProbeState
{
    /**
     * @throws ProbeStaleException
     */
    private function guardProbeState(bool $stale): void
    {
        if ($stale) {
            throw ProbeStaleException::make();
        }
    }

    /**
     * The same shape for an exception the application builds two ways. Which of the two ran is written
     * here and nowhere the caller can see, so a status read at the action has nothing to go on.
     *
     * @throws ExportOfflineException
     */
    private function guardProbeReachable(bool $offline, bool $oversized): void
    {
        if ($offline) {
            throw ExportOfflineException::unavailable();
        }

        if ($oversized) {
            throw ExportOfflineException::tooLarge();
        }
    }
}

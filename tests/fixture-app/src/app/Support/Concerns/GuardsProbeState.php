<?php

declare(strict_types=1);

namespace App\Support\Concerns;

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
}

<?php

declare(strict_types=1);

namespace App\Exceptions\Concerns;

/**
 * The factory an application shares across its exceptions by trait. `new static(…)` here builds whichever
 * class used the trait, so this line is the only place that class's status is written — and it is written
 * in a file PHP reports as the using class's own.
 */
trait BuildsProbeFailures
{
    public static function throttled(): static
    {
        return new static(429, 'The export was throttled.');
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/** A helper that runs the guards an action hands it, which is how two callbacks reach one call. */
final class ProbeGuards
{
    public function either(callable $first, callable $second): void
    {
        $first();
        $second();
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/** A helper that runs the guards an action hands it, which is how two callbacks reach one call. */
final class ProbeGuards
{
    /** Logs the message of what a catch hands it, and lets none of it out. */
    public function note(Throwable $e): void
    {
        Log::warning($e->getMessage(), ['code' => $e->getCode()]);
    }

    public function either(callable $first, callable $second): void
    {
        $first();
        $second();
    }
}

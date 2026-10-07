<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An application helper written as a function: runs the work it is handed and swallows any Exception it
 * throws.
 */
function swallow(callable $work): void
{
    try {
        $work();
    } catch (\Exception $e) {
        report($e);
    }
}

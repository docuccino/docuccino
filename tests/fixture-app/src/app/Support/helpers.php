<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An application helper written as a function: runs the work it is handed and swallows any Exception it
 * throws, logging its message.
 */
function swallow(callable $work): void
{
    try {
        $work();
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::warning($e->getMessage());
    }
}

/** Hands the work on to {@see swallow()}, so its own body names no place the work runs. */
function relay(callable $work): void
{
    swallow($work);
}

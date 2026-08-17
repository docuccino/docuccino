<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Marks a portal error the caller may retry after a stated number of seconds. */
interface HasRetryWindow
{
    public function retryAfter(): int;
}

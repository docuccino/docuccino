<?php

declare(strict_types=1);

namespace App\Support;

/** The application's own status constants, which is where several exceptions get theirs from. */
final class ProbeStatuses
{
    public const ARCHIVED = 415;

    public const REJECTED = 422;

    /** Not a status at all — how many times a relay is retried, sitting beside the numbers that are. */
    public const RETRIES = 3;
}

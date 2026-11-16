<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * An export failure whose backing value IS the HTTP status it answers with.
 */
enum ExportFault: int
{
    case Gone = 410;
    case Locked = 423;

    public function statusCode(): int
    {
        return $this->value;
    }
}

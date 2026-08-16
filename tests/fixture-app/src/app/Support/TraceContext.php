<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Stands in for the observability facade a real app reads a trace id off (`Tracer::traceId()`): a static
 * that answers null whenever nothing is being traced. Only ever analysed.
 */
final class TraceContext
{
    public static function id(): ?string
    {
        $id = $_SERVER['HTTP_X_TRACE_ID'] ?? null;

        return is_string($id) ? $id : null;
    }
}

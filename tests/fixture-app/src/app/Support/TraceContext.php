<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Stands in for the observability facade a real app reads a trace id off (`Tracer::traceId()`): a static
 * that answers null whenever nothing is being traced, and the injected collaborator the same app reaches
 * for once the tracer is a dependency. Only ever analysed.
 */
final class TraceContext
{
    public static function id(): ?string
    {
        $id = $_SERVER['HTTP_X_TRACE_ID'] ?? null;

        return is_string($id) ? $id : null;
    }

    /** The same read through an instance: having the tracer says nothing about it having an id. */
    public function currentId(): ?string
    {
        return self::id();
    }
}

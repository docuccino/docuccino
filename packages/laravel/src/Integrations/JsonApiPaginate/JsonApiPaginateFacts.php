<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\JsonApiPaginate;

/**
 * What a {@see JsonApiPaginateTraceVisitor} recovers from an action's chain: whether it reaches a
 * `jsonPaginate()` terminal at all, and the per-call-site overrides the macro accepts
 * (`jsonPaginate(?maxResults, ?defaultSize)`), folded from the outermost call. A mutable accumulator
 * the visitor writes and the parameters extension reads back once the trace returns.
 */
final class JsonApiPaginateFacts
{
    public bool $paginates = false;

    /** The macro's first argument (`$maxResults`) when folded from a literal at the call site. */
    public ?int $maxResultsOverride = null;

    /** The macro's second argument (`$defaultSize`) when folded from a literal at the call site. */
    public ?int $defaultSizeOverride = null;
}

<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * The one query parameter a Laravel paginator reads to select a page: `page` for the length-aware and
 * simple paginators, `cursor` for the cursor one. Every integration documenting a Laravel-paginated
 * endpoint mints it here, so the two producers of a `page` cannot drift apart.
 *
 * There is deliberately no `per_page` beside it. `paginate()` takes its size from the call site or the
 * model's `$perPage`, never from the request, so an application that honours a page-size key wrote that
 * itself — only its own `#[QueryParameter]` can say so, and guessing one would name a key the endpoint
 * does not read.
 */
final class PaginatorPageParameter
{
    /**
     * The page selector for a paginator kind, under `$name` where the call site renamed the key. An
     * unrecognised kind is treated as length-aware, as {@see PaginationEnvelope} treats it.
     */
    public static function for(?string $kind, ?string $name = null): QueryParameterSpec
    {
        if ($kind === 'cursor') {
            return new QueryParameterSpec(
                $name ?? 'cursor',
                ['type' => 'string'],
                'Opaque cursor for the next/previous page.',
            );
        }

        return new QueryParameterSpec(
            $name ?? 'page',
            ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            'Page number.',
        );
    }
}

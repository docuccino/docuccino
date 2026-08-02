<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * The JSON paginator envelopes Laravel serialises around a page of items (`{data, links, meta}`),
 * shared by every integration that documents a paginated collection — spatie `PaginatedDataCollection`
 * and API-resource `AnonymousResourceCollection::paginate()` alike — so the wrapper shape stays
 * identical whoever produces it. Each builder takes the already-converted item schema and wraps it;
 * `data` is the only guaranteed member (an empty page still carries it).
 */
final class PaginationEnvelope
{
    /**
     * The length-aware paginator shape (`paginate()`): `data` plus first/last/prev/next `links` and a
     * `meta` block with the page counters.
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function length(array $items): array
    {
        return self::wrap($items, [
            'links' => self::object([
                'first' => self::nullableString(),
                'last' => self::nullableString(),
                'prev' => self::nullableString(),
                'next' => self::nullableString(),
            ]),
            'meta' => self::object([
                'current_page' => ['type' => 'integer'],
                'from' => self::nullableInteger(),
                'last_page' => ['type' => 'integer'],
                'path' => self::nullableString(),
                'per_page' => ['type' => 'integer'],
                'to' => self::nullableInteger(),
                'total' => ['type' => 'integer'],
            ]),
        ]);
    }

    /**
     * The simple paginator shape (`simplePaginate()`): like length-aware but without `last_page`/`total`
     * (it never counts the full set).
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function simple(array $items): array
    {
        return self::wrap($items, [
            'links' => self::object([
                'first' => self::nullableString(),
                'last' => self::nullableString(),
                'prev' => self::nullableString(),
                'next' => self::nullableString(),
            ]),
            'meta' => self::object([
                'current_page' => ['type' => 'integer'],
                'from' => self::nullableInteger(),
                'path' => self::nullableString(),
                'per_page' => ['type' => 'integer'],
                'to' => self::nullableInteger(),
            ]),
        ]);
    }

    /**
     * The cursor paginator shape (`cursorPaginate()`): opaque `next_cursor`/`prev_cursor` tokens, no
     * page counters.
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function cursor(array $items): array
    {
        return self::wrap($items, [
            'links' => self::object([
                'first' => self::nullableString(),
                'last' => self::nullableString(),
                'prev' => self::nullableString(),
                'next' => self::nullableString(),
            ]),
            'meta' => self::object([
                'path' => self::nullableString(),
                'per_page' => ['type' => 'integer'],
                'next_cursor' => self::nullableString(),
                'prev_cursor' => self::nullableString(),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $items
     * @param  array<string, array<string, mixed>>  $extra
     * @return array<string, mixed>
     */
    private static function wrap(array $items, array $extra): array
    {
        return [
            'type' => 'object',
            'properties' => ['data' => ['type' => 'array', 'items' => $items]] + $extra,
            'required' => ['data'],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties];
    }

    /**
     * @return array<string, mixed>
     */
    private static function nullableString(): array
    {
        return ['type' => ['string', 'null']];
    }

    /**
     * @return array<string, mixed>
     */
    private static function nullableInteger(): array
    {
        return ['type' => ['integer', 'null']];
    }
}

<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * The paginated envelopes `spatie/laravel-data` serialises around a page of Data items — distinct
 * from Laravel's own resource paginator envelope ({@see PaginationEnvelope}) and NOT interchangeable
 * with it (audit spatie-data gap 7):
 *
 * - `links` is an ARRAY of `{url, label, active}` objects (spatie's `linkCollection()`), not a
 *   `{first,last,prev,next}` object; the cursor variant emits an empty `links` array.
 * - `meta` carries the `*_page_url` members alongside the counters (length-aware) / cursor tokens.
 * - all three of `data`/`links`/`meta` are always emitted, so all three are required.
 *
 * @see \Spatie\LaravelData\Resolvers\TransformedDataCollectableResolver
 */
final class SpatieDataEnvelope
{
    /**
     * The length-aware paginated collection (`PaginatedDataCollection`).
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function length(array $items): array
    {
        return self::wrap($items, [
            'meta' => self::object([
                'current_page' => ['type' => 'integer'],
                'first_page_url' => self::nullableString(),
                'from' => self::nullableInteger(),
                'last_page' => ['type' => 'integer'],
                'last_page_url' => self::nullableString(),
                'next_page_url' => self::nullableString(),
                'path' => self::nullableString(),
                'per_page' => ['type' => 'integer'],
                'prev_page_url' => self::nullableString(),
                'to' => self::nullableInteger(),
                'total' => ['type' => 'integer'],
            ]),
        ]);
    }

    /**
     * The cursor-paginated collection (`CursorPaginatedDataCollection`): `links` is empty and `meta`
     * carries the opaque cursor tokens plus the neighbouring page URLs.
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function cursor(array $items): array
    {
        return self::wrap($items, [
            'meta' => self::object([
                'path' => self::nullableString(),
                'per_page' => ['type' => 'integer'],
                'next_cursor' => self::nullableString(),
                'next_page_url' => self::nullableString(),
                'prev_cursor' => self::nullableString(),
                'prev_page_url' => self::nullableString(),
            ]),
        ]);
    }

    /**
     * `data` (the page of items) + spatie's `links` array-of-objects + the given `meta` block; all
     * three keys are always serialised, so all three are required.
     *
     * @param  array<string, mixed>  $items
     * @param  array<string, array<string, mixed>>  $extra
     * @return array<string, mixed>
     */
    private static function wrap(array $items, array $extra): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => $items],
                'links' => [
                    'type' => 'array',
                    'items' => self::object([
                        'url' => self::nullableString(),
                        'label' => ['type' => 'string'],
                        'active' => ['type' => 'boolean'],
                    ]),
                ],
            ] + $extra,
            'required' => ['data', 'links', 'meta'],
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

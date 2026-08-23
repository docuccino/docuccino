<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Extensions\BuiltIn\EnumSchema;

/**
 * Applies the codegen-facing decoration to an enum schema: SDK member-name hints per the
 * `enums.naming` policy, and per-value descriptions in the two shapes tools consume. The one
 * implementation of these rules — component enums ({@see EnumSchema})
 * and the Laravel adapter's allow-list enums both route through it, so the two can never emit
 * different decoration standards.
 *
 * The `x-enumDescriptions` map is emitted only when every value has prose — Redoc hides values
 * missing from the map, so completeness is that extension's contract. The parallel
 * `x-enum-descriptions` array is emitted whenever at least one value has prose, full length with
 * empty-string gaps, because array consumers apply it by index. Name hints are emitted only when
 * the names line up one-to-one with the values — a short array would silently rename a prefix
 * downstream.
 */
final class EnumDecoration
{
    /**
     * @param  array<string, mixed>  $schema  an enum-bearing schema fragment
     * @param  string  $naming  the `enums.naming` policy keyword
     * @param  list<string>  $names  identifier-safe member names, parallel to the schema's `enum`
     * @param  array<string, string>  $descriptions  prose keyed by the stringified enum value
     * @return array<string, mixed>
     */
    public static function apply(array $schema, string $naming, array $names, array $descriptions): array
    {
        $values = $schema['enum'] ?? null;
        if (! is_array($values) || $values === []) {
            return $schema;
        }

        $keys = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $values);

        $texts = array_map(static fn (string $key): string => $descriptions[$key] ?? '', $keys);
        if (array_filter($texts, static fn (string $text): bool => $text !== '') !== []) {
            if (! in_array('', $texts, true)) {
                $schema['x-enumDescriptions'] = array_combine($keys, $texts);
            }

            $schema['x-enum-descriptions'] = $texts;
        }

        if ($names !== [] && count($names) === count($values)) {
            foreach (self::namingKeys($naming) as $key) {
                $schema[$key] = $names;
            }
        }

        return $schema;
    }

    /**
     * The extension keys a naming keyword emits: `names` (the default) carries both spellings so
     * OpenAPI Generator, NSwag and the TS toolchain are all served; the single-key keywords remain
     * for authors pinning one tool's shape; `none` emits nothing.
     *
     * @return list<string>
     */
    private static function namingKeys(string $naming): array
    {
        return match ($naming) {
            'names' => ['x-enum-varnames', 'x-enumNames'],
            'x-enumNames', 'x-enum-varnames' => [$naming],
            default => [],
        };
    }
}

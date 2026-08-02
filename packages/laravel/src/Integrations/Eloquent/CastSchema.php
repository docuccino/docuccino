<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

/**
 * Maps an Eloquent `$casts` entry to the JSON Schema fragment it serialises to — datetime casts pick
 * up a `date-time`/`date` format, native casts fix a type, decimal/encrypted stay strings. Enum casts
 * (a backed-enum class-string) are routed through the Enum integration instead and return null here,
 * as does any custom caster class, so the column falls back to its inferred type.
 */
final class CastSchema
{
    /**
     * The schema fragment for a cast, or null to fall back to the inferred column type.
     *
     * @return array<string, mixed>|null
     */
    public static function forCast(string $cast): ?array
    {
        // Casts carry parameters after a colon (`datetime:Y-m-d`, `decimal:2`); only the base matters.
        $base = strtolower(explode(':', $cast, 2)[0]);

        return match ($base) {
            'datetime', 'immutable_datetime', 'custom_datetime' => ['type' => 'string', 'format' => 'date-time'],
            'date', 'immutable_date' => ['type' => 'string', 'format' => 'date'],
            'timestamp' => ['type' => 'integer'],
            'boolean', 'bool' => ['type' => 'boolean'],
            'integer', 'int' => ['type' => 'integer'],
            'real', 'float', 'double' => ['type' => 'number'],
            'decimal' => ['type' => 'string'],
            'string', 'encrypted', 'hashed' => ['type' => 'string'],
            'array', 'collection' => ['type' => 'array'],
            'object' => ['type' => 'object'],
            default => null,
        };
    }

    /** Whether a cast value names a backed enum (routed through the Enum integration path). */
    public static function isEnum(string $cast): bool
    {
        $base = explode(':', $cast, 2)[0];

        return enum_exists($base);
    }
}

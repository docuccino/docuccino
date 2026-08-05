<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

/**
 * Maps an Eloquent `$casts` entry to the JSON Schema fragment it serialises to — datetime casts pick
 * up a `date-time`/`date` format (honouring a `datetime:FORMAT` parameter), native casts fix a type,
 * decimal/hashed stay strings, `array`/`collection`/`json` admit either a JSON object or array, and an
 * `encrypted:<inner>` compound decrypts-then-casts to the inner type. Enum casts (a backed-enum
 * class-string) are routed through the Enum integration instead and return null here, as does any
 * custom caster class, so the column falls back to its inferred type.
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
        $parts = explode(':', $cast, 2);
        $base = strtolower($parts[0]);
        $parameter = $parts[1] ?? null;

        // `encrypted:<inner>` decrypts then casts to the inner type — it serialises as that inner type
        // (array/object/string), NOT as an opaque string.
        if ($base === 'encrypted' && $parameter !== null && $parameter !== '') {
            return self::forCast($parameter);
        }

        return match ($base) {
            'datetime', 'immutable_datetime', 'custom_datetime' => self::datetime($parameter),
            'date', 'immutable_date' => ['type' => 'string', 'format' => 'date'],
            'timestamp' => ['type' => 'integer'],
            'boolean', 'bool' => ['type' => 'boolean'],
            'integer', 'int' => ['type' => 'integer'],
            'real', 'float', 'double' => ['type' => 'number'],
            'decimal' => ['type' => 'string'],
            'string', 'encrypted', 'hashed' => ['type' => 'string'],
            // A JSON/array/collection column decodes to whatever it stored — an assoc array is a JSON
            // object, a list is a JSON array — so it admits both. `json:unicode` is the same shape.
            'array', 'collection', 'json' => ['type' => ['array', 'object']],
            'object' => ['type' => 'object'],
            default => null,
        };
    }

    /**
     * The schema for a `datetime` cast, honouring a `datetime:FORMAT` parameter: the default (ISO-8601)
     * and other time-bearing ISO forms are `date-time`; the ISO date-only form is `date`; any other
     * custom format serialises to a bespoke string that is neither, so it is a plain string with the
     * format noted in the description rather than a wrong `format` claim.
     *
     * @return array<string, mixed>
     */
    private static function datetime(?string $format): array
    {
        if ($format === null || $format === '') {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if (in_array($format, ['Y-m-d\\TH:i:sP', 'Y-m-d\\TH:i:s.uP', 'Y-m-d H:i:s', 'c'], true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if ($format === 'Y-m-d') {
            return ['type' => 'string', 'format' => 'date'];
        }

        return ['type' => 'string', 'description' => sprintf('Serialized using the date format "%s".', $format)];
    }

    /** Whether a cast value names a backed enum (routed through the Enum integration path). */
    public static function isEnum(string $cast): bool
    {
        $base = explode(':', $cast, 2)[0];

        return enum_exists($base);
    }
}

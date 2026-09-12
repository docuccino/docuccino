<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Laravel\Integrations\Support\DateWireFormat;

/**
 * Maps an Eloquent `$casts` entry to a JSON Schema fragment: native casts fix a type, decimal/hashed
 * stay strings, `array`/`collection`/`json` admit object OR array, and `encrypted:<inner>`
 * decrypts-then-casts to the inner type.
 *
 * The table is read in TWO directions, because a column's two appearances in the document answer
 * different questions: {@see written()} is what a response body carries for it, {@see accepted()} what
 * a filter value, a scope argument or a bound path segment may put in. Every row answers both alike
 * except the casts `Model::serializeDate()` governs ({@see serializesThroughDateHook()}) — a `date`
 * cast is WRITTEN as a full date-time and ACCEPTED as a date — and there the response direction is not
 * this table's to give: the one date policy decides it ({@see DateColumnSchema}).
 *
 * Anything enum-valued returns null in both directions and is routed through the Enum integration by
 * {@see ModelSchema}, which owns that machinery — a backed-enum cast, `AsEnumCollection:Enum` and
 * `AsEnumArrayObject:Enum` (whose enum parameter {@see enumCollectionEnum()} exposes). An unrecognised
 * custom caster also returns null, leaving the column on its inferred type.
 */
final class CastSchema
{
    private const AS_NAMESPACE = 'Illuminate\\Database\\Eloquent\\Casts\\';

    /**
     * Built-in `As*` class casts with a fixed serialised shape. The `$casts` value is the FQCN, possibly
     * with a trailing `:arg` this table ignores.
     *
     * @var array<string, array<string, mixed>>
     */
    private const CLASS_CASTS = [
        self::AS_NAMESPACE.'AsStringable' => ['type' => 'string'],
        self::AS_NAMESPACE.'AsUri' => ['type' => 'string'],
        self::AS_NAMESPACE.'AsHtmlString' => ['type' => 'string'],
        self::AS_NAMESPACE.'AsFluent' => ['type' => 'object'],
        self::AS_NAMESPACE.'AsArrayObject' => ['type' => 'object'],
        self::AS_NAMESPACE.'AsCollection' => ['type' => 'array'],
        // Decrypt-THEN-cast: these serialise as the decoded JSON value, never the ciphertext string.
        self::AS_NAMESPACE.'AsEncryptedArrayObject' => ['type' => 'object'],
        self::AS_NAMESPACE.'AsEncryptedCollection' => ['type' => 'array'],
    ];

    /** The enum-valued `As*` class casts — an array of the parameterised enum's values. */
    private const AS_ENUM_COLLECTION = [
        self::AS_NAMESPACE.'AsEnumCollection',
        self::AS_NAMESPACE.'AsEnumArrayObject',
    ];

    /**
     * The casts `HasAttributes::addCastAttributesToArray()` hands to `serializeDate()` — the framework's
     * own four, matched as it matches them: the whole cast value, so a parameterised one is not among
     * them. `custom_datetime` is deliberately absent even though {@see fragment()} answers for it: it is
     * the INTERNAL cast-type name, and a `$casts` value spelled that way reaches no branch of that
     * method, so it serialises as Carbon's own JSON and an override never touches it.
     */
    private const DATE_HOOK_CASTS = [
        'date',
        'datetime',
        'immutable_date',
        'immutable_datetime',
    ];

    /**
     * What a response body carries for a column with this cast, or null where the fragment is not this
     * table's to give: a cast the date hook governs is the date policy's to decide
     * ({@see serializesThroughDateHook()}), and an enum-valued or unrecognised cast is routed or falls
     * back as the class docblock says.
     *
     * @return array<string, mixed>|null
     */
    public static function written(string $cast): ?array
    {
        return self::serializesThroughDateHook($cast) ? null : self::fragment($cast);
    }

    /**
     * What a request may put in for a value of this cast — a filter value, a scope argument, a bound
     * path segment. A date-cast column is accepted as a `date` even though it is written as a
     * date-time: the segment a client types is matched against the stored column, not against the
     * serialised attribute.
     *
     * @return array<string, mixed>|null
     */
    public static function accepted(string $cast): ?array
    {
        return self::fragment($cast);
    }

    /**
     * The table itself — the rows both directions read alike.
     *
     * @return array<string, mixed>|null
     */
    private static function fragment(string $cast): ?array
    {
        $parts = explode(':', $cast, 2);
        $base = $parts[0];
        $parameter = $parts[1] ?? null;

        // Class casts match on the FQCN case-sensitively, before the native table lowercases the base.
        if (isset(self::CLASS_CASTS[$base])) {
            return self::CLASS_CASTS[$base];
        }

        // `encrypted:<inner>` serialises as the inner type, not an opaque string.
        if (strtolower($base) === 'encrypted' && $parameter !== null && $parameter !== '') {
            return self::fragment($parameter);
        }

        return match (strtolower($base)) {
            'datetime', 'immutable_datetime', 'custom_datetime' => self::datetime($parameter),
            // The date the column stores, which is the REQUEST answer: written it is a start-of-day
            // date-time, and {@see written()} sends the hook's casts to the date policy for that.
            'date', 'immutable_date' => ['type' => 'string', 'format' => 'date'],
            'timestamp' => ['type' => 'integer'],
            'boolean', 'bool' => ['type' => 'boolean'],
            'integer', 'int' => ['type' => 'integer'],
            'real', 'float', 'double' => ['type' => 'number'],
            'decimal' => ['type' => 'string'],
            'string', 'encrypted', 'hashed' => ['type' => 'string'],
            // Decodes to whatever was stored: an assoc array is an object, a list is an array, so both.
            'array', 'collection', 'json' => ['type' => ['array', 'object']],
            'object' => ['type' => 'object'],
            default => null,
        };
    }

    /**
     * A `datetime` cast honouring its `datetime:FORMAT` parameter, read through the one date policy
     * ({@see DateWireFormat}): an ISO form claims the `format` its values satisfy, and a bespoke one is a
     * plain string with the format noted in the description — better than a `format` claim that would be
     * wrong. An UNPARAMETERISED cast has no format of its own, so this answers for the request
     * direction only; {@see written()} sends it to the date policy instead.
     *
     * @return array<string, mixed>
     */
    private static function datetime(?string $format): array
    {
        if ($format === null || $format === '') {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        return DateWireFormat::serializedSchema($format);
    }

    /** Whether a cast value names an enum. */
    public static function isEnum(string $cast): bool
    {
        $base = explode(':', $cast, 2)[0];

        return enum_exists($base);
    }

    /** The enum FQCN of an `AsEnumCollection:Enum` / `AsEnumArrayObject:Enum` cast. */
    public static function enumCollectionEnum(string $cast): ?string
    {
        $parts = explode(':', $cast, 2);
        $enum = $parts[1] ?? null;

        return in_array($parts[0], self::AS_ENUM_COLLECTION, true) && $enum !== null && $enum !== ''
            ? $enum
            : null;
    }

    /**
     * Whether a cast's value is serialised by `Model::serializeDate()` — the hook an application may
     * override, and so the whole of when a date column's wire format stops being statically knowable
     * ({@see DateColumnSchema}).
     *
     * Two casts that look like dates are not: `timestamp` serialises as a unix integer, and a
     * PARAMETERISED cast is formatted with its own parameter and never reaches the hook, so an override
     * takes nothing away from it. "Parameterised" is read exactly as {@see datetime()} reads it — an
     * empty parameter is none — so the guard cannot recognise fewer forms than the fragment it decides.
     */
    public static function serializesThroughDateHook(string $cast): bool
    {
        $parts = explode(':', $cast, 2);

        return ($parts[1] ?? '') === ''
            && in_array(strtolower($parts[0]), self::DATE_HOOK_CASTS, true);
    }
}

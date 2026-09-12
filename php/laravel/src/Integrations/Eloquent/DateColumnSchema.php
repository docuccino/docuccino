<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Laravel\Integrations\Support\DateWireFormat;

/**
 * What a model's date attribute publishes, and the ONE place that decides it — a response body column
 * and a route-bound path segment both come through here, so a column cannot be dated one way in one
 * and another way in the other.
 *
 * Every date a model serialises goes through `serializeDate()`, whatever type a `@property` tag or a
 * cast gave the column — so the tag never decides the shape, and a date-time class named by one is a
 * PHP object that no response carries. The default hook writes Carbon's JSON form, which is where the
 * `date-time` claim comes from; an override sends a bespoke string no analysis can name and the
 * `format` is given up. That loss is the whole of what `eloquent.custom-date-serialization` reports,
 * which is why {@see schema()} raises the flag itself: a caller that publishes the shape cannot publish
 * it without reporting.
 *
 * @phpstan-import-type ModelFacts from EloquentModelReflector
 */
final class DateColumnSchema
{
    /**
     * What `Model::serializeDate()` writes — Carbon's JSON form — so the `date-time` claim is derived
     * from the bytes rather than asserted.
     */
    public const DEFAULT_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /**
     * The framework's own date columns, in the order a response carries them.
     *
     * @var list<string>
     */
    public const TIMESTAMPS = ['created_at', 'updated_at'];

    public const DELETED_AT = 'deleted_at';

    /**
     * The shape a date attribute publishes. `$formatGivenUp` is RAISED — never cleared, so a model with
     * several date attributes stays flagged by the first one that lost its format.
     *
     * @param  ModelFacts  $facts
     * @return array<string, mixed>
     */
    public static function schema(array $facts, bool &$formatGivenUp): array
    {
        if (! $facts['overridesSerializeDate']) {
            return DateWireFormat::serializedSchema(self::DEFAULT_FORMAT);
        }

        $formatGivenUp = true;

        return ['type' => 'string'];
    }

    /**
     * Whether the model treats this column as a date attribute: a date cast, a `$dates` entry, or a
     * framework timestamp / soft-delete column it really has.
     *
     * @param  ModelFacts  $facts
     */
    public static function isAttribute(string $column, array $facts): bool
    {
        $cast = $facts['casts'][$column] ?? null;

        return ($cast !== null && CastSchema::isDateCast($cast))
            || in_array($column, $facts['dates'], true)
            || ($facts['timestamps'] && in_array($column, self::TIMESTAMPS, true))
            || ($facts['softDeletes'] && $column === self::DELETED_AT);
    }
}

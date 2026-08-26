<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * A PHP `date()` format string → what a value written with it looks like on the wire. One home for the
 * mapping because both directions ask it: the response side reads the app's `data.date_format`, the
 * request side reads whatever the property's most specific source states, and a second copy of the
 * mapping is a second answer to the same question.
 */
final class DateWireFormat
{
    /** Spatie's own `data.date_format` default (`DATE_ATOM`), so an unconfigured app documents what it sends. */
    public const DEFAULT_FORMAT = 'Y-m-d\TH:i:sP';

    /** The one PHP format whose value is an integer rather than a formatted string. */
    public const UNIX = 'U';

    /** {@see shape()}'s answer for that integer, the one shape with no OAS `format` to name it. */
    public const TIMESTAMP = 'timestamp';

    /** A format bearing a time or zone token → `date-time`; a date-only one → `date`. */
    public static function oas(string $phpFormat): string
    {
        return preg_match('/[GHhisuveTPOaA]/', $phpFormat) === 1 ? 'date-time' : 'date';
    }

    /** The wire shape a value formatted this way publishes: {@see TIMESTAMP}, or an OAS `format`. */
    public static function shape(string $phpFormat): string
    {
        return $phpFormat === self::UNIX ? self::TIMESTAMP : self::oas($phpFormat);
    }
}

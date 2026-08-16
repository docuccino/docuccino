<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Makes a string lifted out of a document safe to print. A diff is run against an artifact nobody
 * re-read first, and a name, path or version in it can carry escape sequences, carriage returns or
 * newlines that recolour an operator's terminal or forge a line of a CI log. Control characters become a
 * visible `\xNN`; everything else, non-ASCII included, is left exactly as written, because a name or a
 * description may legitimately carry it.
 *
 * Byte-wise on purpose: every byte of a multi-byte character is >= 0x80, so none of them match, and
 * malformed input is escaped rather than rejected.
 *
 * @internal
 */
final class PlainText
{
    private const string CONTROL = '/[\x00-\x1F\x7F]/';

    public static function of(string $text): string
    {
        return (string) preg_replace_callback(self::CONTROL, self::escape(...), $text);
    }

    /**
     * @param  array<int|string, string>  $match
     */
    private static function escape(array $match): string
    {
        return sprintf('\x%02X', ord($match[0]));
    }
}

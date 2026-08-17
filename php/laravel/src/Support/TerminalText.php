<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Support\PlainText;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Makes a string safe to hand to a console writer. Two hazards, two halves: {@see PlainText} covers what
 * steers a terminal directly, and the formatter escape over it covers Symfony's own markup, which
 * `line()` and `write()` would otherwise INTERPRET — `<fg=red>` recolours the operator's terminal and
 * `<info>` vanishes from the report. The formatter undoes the escape as it writes, so a legitimate
 * `array<int, string>` still reads exactly as written.
 *
 * Order matters where both halves apply: {@see PlainText} first, so the NUL its trailing-backslash escape
 * inserts is consumed by the formatter rather than printed.
 *
 * Escaping belongs here, at the render boundary, rather than at the producer: the same value goes to JSON
 * and document outputs too, where `json_encode` escapes already and a second pass only garbles it.
 *
 * @internal
 */
final class TerminalText
{
    /**
     * A string lifted out of an application or an artifact, as a terminal may show it.
     */
    public static function of(string $value): string
    {
        return OutputFormatter::escape(PlainText::of($value));
    }

    /**
     * Text a core renderer already put through {@see PlainText} — only the markup half is still owed, and
     * a second pass would escape the backslashes the first one wrote.
     */
    public static function markupOnly(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}

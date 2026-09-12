<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

/**
 * The field-path grammar the request body is written in: a `.` separates one name from the next, a
 * `*` segment stands for every element of an array, and `\.` is a dot that belongs to the name rather
 * than separating two. It is Laravel's validation-key grammar because the body is assembled from
 * validation keys — and once one reader of a body path folds an escape, every reader of a body path
 * has to, or the same string means two things depending on who read it.
 *
 * The same path has a second spelling in a query string, where a nested name rides as brackets:
 * {@see toQueryName()} writes it and {@see fromQueryName()} reads it back. Both live here so the
 * writer and the reader of that spelling cannot drift — a guard matching a declared parameter name
 * against a validation key has to recognise exactly the names the write produced.
 *
 * Not to be confused with the adapter's `Integrations\Support\FieldPaths`, which asks what a SET of
 * recovered rule keys says about one field's container. This is the split itself.
 */
final class FieldPath
{
    /**
     * The path's segments, escapes resolved. An empty segment — a leading, trailing or doubled `.`,
     * or an empty path — is kept rather than dropped, because it is the caller's evidence that the
     * string names no field at all.
     *
     * @return non-empty-list<string>
     */
    public static function segments(string $path): array
    {
        $segments = [];
        $current = '';
        $length = strlen($path);

        for ($i = 0; $i < $length; $i++) {
            $character = $path[$i];

            // Laravel's own escape, and read the way Laravel reads it: the backslash disappears only
            // in front of a dot, so a lone backslash stays part of the name.
            if ($character === '\\' && ($path[$i + 1] ?? '') === '.') {
                $current .= '.';
                $i++;

                continue;
            }

            if ($character === '.') {
                $segments[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $segments[] = $current;

        return $segments;
    }

    /** Whether every segment names something — the check a caller owes before walking the path. */
    public static function isWellFormed(string $path): bool
    {
        return ! in_array('', self::segments($path), true);
    }

    /**
     * The bracketed name a path takes in a query string: `filter.radius_lat` rides as
     * `filter[radius_lat]`, and a top-level field is its own name. Escapes are already folded — the
     * segments are the names themselves — because brackets, not dots, separate them on the wire.
     *
     * @param  non-empty-list<string>  $segments
     */
    public static function toQueryName(array $segments): string
    {
        $name = array_shift($segments);

        foreach ($segments as $segment) {
            $name .= '['.$segment.']';
        }

        return $name;
    }

    /**
     * The path a bracketed query name points at — {@see toQueryName()} read backwards. A dot inside a
     * segment is the name's own, so it comes back escaped: the wire spelling has no separator to
     * confuse it with, and the path grammar does.
     */
    public static function fromQueryName(string $name): string
    {
        $segments = preg_split('/\]\[|\[|\]$/', $name) ?: [$name];

        return implode('.', array_map(
            static fn (string $segment): string => str_replace('.', '\\.', $segment),
            array_values(array_filter($segments, static fn (string $segment): bool => $segment !== '')),
        ));
    }

    /**
     * Whether `$path` names `$ancestor` itself or something inside it — one path answering for another.
     * Compared segment by segment rather than with a string prefix, because `meta\.scoring` and
     * `meta.scoring` share every character and name different things.
     */
    public static function isAtOrUnder(string $path, string $ancestor): bool
    {
        $under = self::segments($ancestor);
        $of = self::segments($path);

        return count($of) >= count($under) && array_slice($of, 0, count($under)) === $under;
    }
}

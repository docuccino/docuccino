<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * The OAS parameter locations, and how a declaration's `in:` is read against them.
 *
 * Stated once because two readers of one author-written word have to agree: `#[IgnoreParam(in: 'Query')]`
 * and `#[RenamedParameter(in: 'Query')]` either both mean `query` or the vocabulary is inconsistent in a
 * way nobody can look up. Case is folded for the same reason — a spelling the tool understands is not
 * worth making an author check the docs for — and a value naming no location at all comes back as null
 * so the caller can report it rather than guess.
 *
 * Alphabetical, because a legal set a reader checks their spelling against is easier to read in an
 * order they can predict than in the one OAS happens to list.
 *
 * @internal
 */
final class ParameterLocations
{
    /** @var list<string> */
    public const array ALL = ['cookie', 'header', 'path', 'query'];

    /** `$in` as the document spells it, or null where it names no location. */
    public static function read(string $in): ?string
    {
        $normalized = strtolower(trim($in));

        return in_array($normalized, self::ALL, true) ? $normalized : null;
    }

    /** The legal set as a diagnostic quotes it. */
    public static function quoted(): string
    {
        return '`'.implode('`, `', self::ALL).'`';
    }
}

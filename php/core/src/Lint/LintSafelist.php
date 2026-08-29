<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

use Docuccino\Core\Support\Glob;

/**
 * The one reading of an entry that names a subject, shared by everything that consults one — every
 * lint, the recorder's redaction, and the scope an API version change declares. A pointer reaches a
 * safelist spelled either bare (`/components/…`, the form a message prints) or as the URI fragment a
 * `$ref` uses (`#/components/…`), so the leading `#` comes off both the entry and the subject and all
 * four combinations land.
 *
 * An entry is a {@see Glob} pattern, which is the product's one wildcard grammar rather than a second
 * one: an entry with no `*` in it matches exactly what it spells, so this is what an exact-match reader
 * always was plus the wildcard a reader would otherwise write beside it. That beside-it reader is the
 * defect this class exists to prevent — the lint honoured the `#/…` fragment form while the recorder's
 * redaction did not, so one config entry silenced a warning and still refused to publish the value it
 * was written for.
 *
 * @internal
 */
final class LintSafelist
{
    /**
     * Whether any of the names a subject goes by is named by one of the entries.
     *
     * @param  list<string>  $allow
     */
    public static function matches(array $allow, ?string ...$subjects): bool
    {
        $entries = array_map(self::canonical(...), $allow);

        foreach ($subjects as $subject) {
            if ($subject !== null && Glob::matchesAny($entries, self::canonical($subject))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The fragment marker off a pointer. Only `#/` counts, so a subject that is a name rather than a
     * pointer — a tag, an operationId, a property — is never rewritten by a `#` it happens to start with.
     */
    private static function canonical(string $subject): string
    {
        return str_starts_with($subject, '#/') ? substr($subject, 1) : $subject;
    }
}

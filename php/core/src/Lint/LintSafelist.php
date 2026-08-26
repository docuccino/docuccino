<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

/**
 * The one reading of a `lint.*.allow` entry, shared by every lint that has one.
 *
 * A pointer is spelled two ways in front of an author: a finding's message prints the bare RFC 6901
 * pointer (`/components/schemas/Invoice/properties/status/example`), while every `$ref` the emitted
 * document publishes spells the same path as a URI fragment (`#/components/…`). Reaching for the
 * fragment form is the instinct rather than the slip, and a safelist that quietly matched only one of
 * them left a config value doing nothing — which is worse than no config value at all. So the leading
 * `#` comes off both the entry and the subject, and all four combinations land.
 *
 * @internal
 */
final class LintSafelist
{
    /**
     * Whether any of the names a finding goes by is safelisted.
     *
     * @param  list<string>  $allow
     */
    public static function matches(array $allow, ?string ...$subjects): bool
    {
        $entries = array_map(self::canonical(...), $allow);

        foreach ($subjects as $subject) {
            if ($subject !== null && in_array(self::canonical($subject), $entries, true)) {
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

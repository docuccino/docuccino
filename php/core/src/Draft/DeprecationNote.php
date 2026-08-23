<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

/**
 * The paragraph a deprecation reason publishes in an operation's description. `deprecated: true` is
 * the machine-readable fact; the reason is the why, and the description is the only member OpenAPI
 * gives it. Both spellings — `#[DeprecatedOperation(reason:)]` and the text after a `@deprecated`
 * tag — come through here, so neither can word it differently from the other.
 */
final class DeprecationNote
{
    /** The note for a reason, or null where the reason says nothing. */
    public static function paragraph(?string $reason): ?string
    {
        $reason = trim($reason ?? '');

        // Marked, because a reader meeting the paragraph on its own — a description dumped without the
        // deprecated flag beside it — has nothing else to tell them what it is about.
        return $reason === '' ? null : '**Deprecated:** '.$reason;
    }
}

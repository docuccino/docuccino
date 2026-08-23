<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

/**
 * The first prose paragraph of a docblock — the one home for summary extraction, shared by enum-case
 * descriptions and the adapter's relation-method reads. Hand-rolled marker stripping (not tag
 * parsing) so core stays free of the phpdoc-parser dependency.
 */
final class DocSummary
{
    /**
     * Strip the markers and per-line `*`, stop at the first blank or `@tag` line, collapse to one
     * trimmed string; null when there is no prose.
     */
    public static function of(string|false $doc): ?string
    {
        if ($doc === false) {
            return null;
        }

        $body = preg_replace('#^\s*/\*\*+|\*+/\s*$#', '', $doc) ?? '';
        $paragraph = [];
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim(ltrim(trim($line), '*'));

            if ($line === '' || str_starts_with($line, '@')) {
                if ($paragraph !== []) {
                    break;
                }

                continue;
            }

            $paragraph[] = $line;
        }

        $summary = trim(implode(' ', $paragraph));

        return $summary === '' ? null : $summary;
    }
}

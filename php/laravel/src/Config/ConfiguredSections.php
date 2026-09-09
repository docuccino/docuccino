<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Config\ConfigValues;

/**
 * Puts every section `docuccino.yaml` declares to the typed reader, so a section holding something
 * other than a section is refused rather than read as empty.
 *
 * The refusals are the reader's own ({@see ConfigValues::map()}) and travel with it, so there is no
 * diagnostic here and a section two callers ask about is still one line. What is here is the ASKING.
 * Most of a document's sections — `routes`, `security`, `tags`, `representation` — are read off a
 * plain bag by whichever reader owns them, so nothing ever put the question to the typed reader:
 * `routes: 'api/*'` emptied a document's whole route filter and published every route in the
 * application without a word.
 *
 * Which paths are sections is read off the shipped file ({@see DeclaredSettings::sections()}) rather
 * than listed here, so a section added to the product cannot stay unasked.
 *
 * @internal
 */
final class ConfiguredSections
{
    /**
     * Ask about every section the file writes, recording a refusal on `$build`'s reader for each one
     * that is no section.
     */
    public static function read(BuildConfig $build): void
    {
        self::walk($build->values(), $build->all(), []);
    }

    /**
     * @param  array<array-key, mixed>  $bag
     * @param  list<string>  $normal  the path with author-chosen names as `*`, which is what is judged
     */
    private static function walk(ConfigValues $values, array $bag, array $normal): void
    {
        $keyed = $normal !== [] && in_array($normal[count($normal) - 1], DeclaredSettings::KEYED_MAPS, true);

        foreach ($bag as $key => $value) {
            $childNormal = [...$normal, $keyed ? '*' : (string) $key];
            $path = implode('.', $childNormal);

            if (! in_array($path, DeclaredSettings::sections(), true)) {
                continue;
            }

            // One segment at a time, and never a dotted path: a dot addresses structure, and a
            // document key is a word its author chose — which may hold one.
            $section = $values->map((string) $key);

            // A refused section answers no keys, and nothing below an open subtree is Docuccino's to
            // judge ({@see UnknownSettings::OPEN}), so neither is descended into.
            if (is_array($value) && ! array_is_list($value) && ! UnknownSettings::isOpen($path)) {
                self::walk($section, $value, $childNormal);
            }
        }
    }
}

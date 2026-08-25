<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Symfony\Component\Yaml\Yaml;

/**
 * Deterministic YAML writer. Member order is the caller's job — feed it canonical output; Symfony's
 * dumper keeps the insertion order it's given, so the same canonical input gives byte-identical YAML.
 *
 * Style: 2-space indent, block collections at every depth (only empty maps/lists go inline, as
 * `{  }` / `[]`), multi-line strings as literal blocks.
 *
 * The value must reach the dumper with its map-versus-sequence carrier intact: `stdClass` is the
 * canonicalizer's empty-map marker and DUMP_OBJECT_AS_MAP writes it `{  }`, while an empty `array`
 * is a genuine sequence and DUMP_EMPTY_ARRAY_AS_SEQUENCE writes it `[]`. Casting objects to arrays
 * here would collapse the two and emit `paths: []`, which is spec-invalid.
 *
 * @internal
 */
final class YamlSerializer
{
    private const int BLOCK_DEPTH = 512;

    private const int INDENT = 2;

    public function serialize(mixed $value): string
    {
        $flags = Yaml::DUMP_OBJECT_AS_MAP
            | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
            | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE;

        return Yaml::dump($value, self::BLOCK_DEPTH, self::INDENT, $flags);
    }
}

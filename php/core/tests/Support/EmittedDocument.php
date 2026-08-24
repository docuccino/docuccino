<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Support;

use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * An emitted document as an object graph — `stdClass` per map, `array` per sequence — plus the
 * comparisons that read it. The kind distinction is the whole point: it is what `json_decode` without
 * `true` preserves and what a plain `Yaml::parse()` throws away.
 *
 * `Yaml::parse()` answers a PHP array for a mapping AND a PHP array for a sequence, so `paths: {}` and
 * `paths: []` decode identically — which is why every round-trip assertion in the suite stayed green while
 * `--yaml` shipped `paths: []` for a `paths` that is an empty MAP. {@see parseYaml()} uses
 * `PARSE_OBJECT_FOR_MAP`, which answers `stdClass` for a mapping, so the two serialisations of one
 * document become comparable position by position.
 */
final class EmittedDocument
{
    /** Emitted YAML, read back without losing the map/sequence distinction. */
    public static function parseYaml(string $yaml): mixed
    {
        return Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP);
    }

    /**
     * Every position where the JSON and YAML emissions of ONE document disagree, one line each. Kinds
     * first — `map` vs `sequence` is the failure that shipped — then scalar values, which catch YAML's
     * own coercions (an unquoted `y`, a bare `1.0`, a date read back as something else).
     *
     * @return list<string>
     */
    public static function differences(mixed $json, mixed $yaml, string $pointer = ''): array
    {
        $at = $pointer === '' ? '/' : $pointer;

        if (self::kind($json) !== self::kind($yaml)) {
            return [sprintf('%s: json is %s, yaml is %s', $at, self::kind($json), self::kind($yaml))];
        }

        if ($json instanceof stdClass) {
            /** @var stdClass $yaml */
            $differences = [];

            foreach (get_object_vars($json) as $key => $value) {
                $differences = [
                    ...$differences,
                    ...self::differences($value, $yaml->{$key} ?? null, $pointer.'/'.self::escape((string) $key)),
                ];
            }

            foreach (array_diff(array_keys(get_object_vars($yaml)), array_keys(get_object_vars($json))) as $key) {
                $differences[] = sprintf('%s: yaml carries a member json does not', $pointer.'/'.self::escape((string) $key));
            }

            return $differences;
        }

        if (is_array($json)) {
            /** @var array<array-key, mixed> $yaml */
            $differences = [];

            foreach ($json as $index => $value) {
                $differences = [...$differences, ...self::differences($value, $yaml[$index] ?? null, $pointer.'/'.$index)];
            }

            if (count($yaml) > count($json)) {
                $differences[] = sprintf('%s: yaml has %d items, json has %d', $at, count($yaml), count($json));
            }

            return $differences;
        }

        return $json === $yaml ? [] : [sprintf('%s: json %s, yaml %s', $at, var_export($json, true), var_export($yaml, true))];
    }

    /** How many positions a walk of $node visits — the anti-vacuity count for any of the above. */
    public static function nodes(mixed $node): int
    {
        if ($node instanceof stdClass) {
            return 1 + array_sum(array_map(self::nodes(...), array_values(get_object_vars($node))));
        }

        if (is_array($node)) {
            return 1 + array_sum(array_map(self::nodes(...), $node));
        }

        return 1;
    }

    /**
     * Where every empty MAP sits, as JSON pointers — the shape a YAML serialiser has to get right.
     *
     * @return list<string>
     */
    public static function emptyMaps(mixed $node, string $pointer = ''): array
    {
        if ($node instanceof stdClass) {
            $members = get_object_vars($node);

            if ($members === []) {
                return [$pointer === '' ? '/' : $pointer];
            }

            $found = [];
            foreach ($members as $key => $value) {
                $found = [...$found, ...self::emptyMaps($value, $pointer.'/'.self::escape((string) $key))];
            }

            return $found;
        }

        if (is_array($node)) {
            $found = [];
            foreach ($node as $index => $value) {
                $found = [...$found, ...self::emptyMaps($value, $pointer.'/'.$index)];
            }

            return $found;
        }

        return [];
    }

    private static function kind(mixed $value): string
    {
        return match (true) {
            $value instanceof stdClass => 'map',
            is_array($value) => 'sequence',
            default => get_debug_type($value),
        };
    }

    private static function escape(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}

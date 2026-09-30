<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use Opis\JsonSchema\JsonPointer;

/**
 * Which of a root's `$defs` a schema can reach through `$ref`, read the way the validator resolves one:
 * a pointer from the root, decoded by the validator's own parser, so an escaped or percent-encoded
 * segment names exactly the member it will resolve to. A schema that names another any other way — an
 * anchor, a base URI, a relative pointer, a template, a dynamic reference — gets null, which means every
 * `$def` has to travel with it. Instance data is read as if it were schema, which can only over-count.
 *
 * @internal
 */
final class ReachableDefs
{
    /**
     * Members that let a schema be named some way other than by a pointer from the root — a base URI, an
     * anchor, a dynamic reference, or a dialect with its own rules for those.
     *
     * @var list<string>
     */
    private const array ADDRESSING = ['$id', '$anchor', '$dynamicAnchor', '$dynamicRef', '$recursiveAnchor', '$recursiveRef', '$schema'];

    /**
     * The `$defs` names a schema references directly, or null where it references anything some other way.
     *
     * @return list<string>|null
     */
    public static function of(mixed $schema): ?array
    {
        $names = [];
        $pending = [$schema];

        while ($pending !== []) {
            $current = array_pop($pending);
            $object = is_object($current);
            $members = $object ? get_object_vars($current) : (is_array($current) ? $current : []);

            foreach ($members as $member => $value) {
                if ($object && in_array($member, self::ADDRESSING, true)) {
                    return null;
                }

                if ($object && $member === '$ref') {
                    $name = is_string($value) ? self::named($value) : false;

                    if ($name === false) {
                        return null;
                    }

                    if ($name !== null) {
                        $names[] = $name;
                    }

                    continue;
                }

                if (is_object($value) || is_array($value)) {
                    $pending[] = $value;
                }
            }
        }

        return $names;
    }

    /**
     * Every name reachable from `$names`, following each `$def`'s own references — or null, meaning all of
     * them, where any on the way cannot be read. A name `$reaches` does not hold is skipped: that reference
     * resolves to nothing whatever travels with it.
     *
     * @param  list<string>|null  $names
     * @param  array<array-key, list<string>|null>  $reaches  each `$def`'s own {@see of()}
     * @return array<array-key, true>|null
     */
    public static function closure(?array $names, array $reaches): ?array
    {
        if ($names === null) {
            return null;
        }

        $reachable = [];

        while ($names !== []) {
            $name = array_pop($names);

            if (isset($reachable[$name]) || ! array_key_exists($name, $reaches)) {
                continue;
            }

            $reachable[$name] = true;
            $next = $reaches[$name];

            if ($next === null) {
                return null;
            }

            foreach ($next as $each) {
                $names[] = $each;
            }
        }

        return $reachable;
    }

    /**
     * The `$defs` member a reference points into; null for a pointer anywhere else in the root; false for
     * any reference that is not a plain pointer from the root.
     */
    private static function named(string $ref): string|false|null
    {
        if ($ref === '#') {
            return null;
        }

        // The validator expands a template before it resolves one, so what it names is decided at run time.
        if (! str_starts_with($ref, '#/') || str_contains($ref, '{')) {
            return false;
        }

        $pointer = JsonPointer::parse(substr($ref, 1));

        if ($pointer === null || ! $pointer->isAbsolute()) {
            return false;
        }

        $path = $pointer->path();

        if (($path[0] ?? null) !== '$defs') {
            return null;
        }

        return isset($path[1]) ? (string) $path[1] : false;
    }
}

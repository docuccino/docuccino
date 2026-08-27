<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use Docuccino\Core\Draft\SchemaKeywords;
use stdClass;

/**
 * Query, path, header and cookie values arrive as strings whatever the contract says they are, so
 * checking `?page=2` against `type: integer` needs the string read back as the type it stands for.
 *
 * Only a string that unambiguously IS the documented type is converted. `?page=abc` against
 * `type: integer` stays a string, so the failure reads "must match the type: integer" — the real
 * problem — instead of `abc` silently becoming `0`.
 *
 * What the contract "says" has to be read in the SAME grammar {@see SchemaCheck} hands the validator,
 * which resolves a local `$ref` and unwraps `allOf`/`anyOf`/`oneOf` before it decides anything. A
 * reader that only sees a literal `type` on the node in front of it converts nothing behind a
 * reference or a composition — so the validator checks an integer parameter against the wire string
 * and fails a request that was fine. Every one of those spellings is something the generator itself
 * emits: `representation.nullable = 'anyof'` writes a nullable parameter as an `anyOf`, the 3.0
 * downlevel emitter rewrites a multi-type `type` as one and hoists `$ref` siblings into an `allOf`,
 * and an enum-backed allow-list publishes `items: {$ref: …}`.
 *
 * @internal
 *
 * @phpstan-type Flattened array{types: list<string>, items: array<string, mixed>|null, properties: array<string, array<string, mixed>>}
 */
final class ParameterValue
{
    /**
     * A composition nested this deep is a document defending itself against a reader rather than one a
     * reader meant to write. {@see Refs} bounds a straight `$ref` chain; this bounds everything else,
     * a schema that composes its way back to itself (`A: {allOf: [{$ref: A}]}`) included — which is
     * legal, and a walk of it with no bound is a hang.
     */
    private const int MAX_DEPTH = 8;

    /**
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>  $document  the whole contract, so a local `$ref` resolves; the
     *                                          empty default resolves nothing, which is the same
     *                                          well-defined answer as a reference nothing defines
     */
    public static function coerce(mixed $value, ?array $schema, array $document = []): mixed
    {
        $flat = self::flatten($schema, $document, 0);

        if (is_string($value)) {
            $value = self::fromString($value, $flat, $document);
        }

        if (is_array($value)) {
            return self::fromArray($value, $flat, $document);
        }

        return $value;
    }

    /**
     * @param  Flattened  $flat
     * @param  array<string, mixed>  $document
     */
    private static function fromString(string $value, array $flat, array $document): mixed
    {
        $types = $flat['types'];

        // Where the contract permits a STRING, the value that arrived already satisfies it, and reading
        // it back as something else can only take a pass away: `anyOf: [{integer, minimum: 100}, {string}]`
        // accepts `?limit=42` exactly as sent and rejects the integer 42. So a union that admits several
        // types resolves toward the wire — convert only where the string cannot stand as itself.
        if (in_array('string', $types, true)) {
            return $value;
        }

        if (in_array('integer', $types, true) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (in_array('number', $types, true) && is_numeric($value)) {
            return (float) $value;
        }

        if (in_array('boolean', $types, true) && in_array($value, ['true', 'false', '1', '0'], true)) {
            return $value === 'true' || $value === '1';
        }

        // `?sort=name,-created_at` is the comma list representation the generator documents by default.
        if (in_array('array', $types, true)) {
            return array_map(
                static fn (string $item): mixed => self::coerce($item, $flat['items'], $document),
                explode(',', $value),
            );
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  Flattened  $flat
     * @param  array<string, mixed>  $document
     */
    private static function fromArray(array $value, array $flat, array $document): mixed
    {
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::coerce($item, $flat['items'], $document), $value);
        }

        // A bracketed query parameter (`filter[status]=paid`) arrives as a map: an object to JSON Schema.
        $object = new stdClass;

        foreach ($value as $key => $item) {
            $object->{(string) $key} = self::coerce($item, $flat['properties'][(string) $key] ?? null, $document);
        }

        return in_array('array', $flat['types'], true) ? array_values(get_object_vars($object)) : $object;
    }

    /**
     * Everything the validator would read off this node about what a value here may be: the types it
     * permits, the `items` it holds each entry of a list to, and the `properties` it names.
     *
     * One walk rather than three, because three readers of one grammar is the defect this fixes: the
     * `items` behind a `$ref` has to come from the same resolution the types did, or a list documented
     * as `{$ref: IdList}` splits on the comma and then leaves every entry a string.
     *
     * `allOf` is a conjunction and the other two are disjunctions, but all three are read as the UNION
     * of their branches. A union can only ever widen the type set, and widening is safe in the one
     * direction that matters: the extra type it admits is either coercible — in which case the value
     * matches a branch the document does publish — or it is `string`, which stops conversion outright.
     *
     * The composition keywords come from {@see SchemaKeywords}'s schema-list position rather than from
     * a copy of the names, less `prefixItems`: its branches position the ELEMENTS of a tuple rather
     * than state alternative readings of the value itself, so a type read out of one would be the type
     * of a member and not of this.
     *
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>  $document
     * @return Flattened
     */
    private static function flatten(?array $schema, array $document, int $depth): array
    {
        $empty = ['types' => [], 'items' => null, 'properties' => []];

        if ($schema === null || $depth > self::MAX_DEPTH) {
            return $empty;
        }

        [$node, , $dangling] = Refs::follow($document, $schema, []);

        // A reference the document does not define makes the WHOLE node unreadable — a `type` sibling
        // does not stand in for the half that would not resolve, because the node means "whatever that
        // names AND this", and half of it is unknown. Reading no type here is not a quiet "nothing to
        // check": {@see SchemaCheck} hands the same node to the validator, which cannot resolve it
        // either and throws, and {@see ContractChecker::validate()} turns that into a violation naming
        // the pointer that went nowhere. The check this feeds is already going to fail and say why.
        if ($dangling !== null) {
            return $empty;
        }

        $types = self::declared($node);

        // An `enum` with no `type` beside it still names a closed set, and the members say what type
        // the set is. Members of several types leave `string` in the union, which is the answer that
        // converts nothing — so an ambiguous set needs no case of its own.
        if ($types === []) {
            $types = self::enumTypes($node);
        }

        $items = self::member($node, 'items');
        $properties = self::propertyMap($node);

        foreach (self::compositions() as $keyword) {
            $branches = $node[$keyword] ?? null;

            if (! is_array($branches)) {
                continue;
            }

            foreach ($branches as $branch) {
                if (! is_array($branch)) {
                    continue;
                }

                /** @var array<string, mixed> $branch */
                $inner = self::flatten($branch, $document, $depth + 1);

                $types = [...$types, ...$inner['types']];
                // The first branch that names one wins, exactly as the node's own does over a branch's.
                $items ??= $inner['items'];
                $properties += $inner['properties'];
            }
        }

        return ['types' => array_values(array_unique($types)), 'items' => $items, 'properties' => $properties];
    }

    /**
     * The keywords whose branches each state a reading of the value in front of us. {@see flatten()}
     * says why `prefixItems` is subtracted from the schema-list position rather than listed with them.
     *
     * @return list<string>
     */
    private static function compositions(): array
    {
        return array_values(array_diff(SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST), ['prefixItems']));
    }

    /**
     * The types this node states outright: `type: integer`, or `type: [integer, null]`.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function declared(array $node): array
    {
        $type = $node['type'] ?? null;

        if (is_string($type)) {
            return [$type];
        }

        if (! is_array($type)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $one): string => is_string($one) ? $one : '',
            $type,
        ), static fn (string $one): bool => $one !== ''));
    }

    /**
     * The JSON types of an `enum`'s own members. Anything a decoded document cannot hold at all reads
     * as `string`, which is the reading that converts nothing.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function enumTypes(array $node): array
    {
        $enum = $node['enum'] ?? null;

        if (! is_array($enum)) {
            return [];
        }

        $types = [];

        foreach ($enum as $member) {
            $types[] = match (true) {
                $member === null => 'null',
                is_bool($member) => 'boolean',
                is_int($member) => 'integer',
                is_float($member) => 'number',
                is_array($member) => array_is_list($member) ? 'array' : 'object',
                default => 'string',
            };
        }

        return array_values(array_unique($types));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private static function member(array $node, string $keyword): ?array
    {
        $value = $node[$keyword] ?? null;

        /** @var array<string, mixed>|null */
        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, array<string, mixed>>
     */
    private static function propertyMap(array $node): array
    {
        $properties = $node['properties'] ?? null;

        if (! is_array($properties)) {
            return [];
        }

        $out = [];
        foreach ($properties as $name => $property) {
            if (is_array($property)) {
                /** @var array<string, mixed> $property */
                $out[(string) $name] = $property;
            }
        }

        return $out;
    }
}

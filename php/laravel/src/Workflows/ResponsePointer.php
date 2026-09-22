<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Workflows;

use Docuccino\Core\Document\DocumentGraph;
use Docuccino\Core\Draft\SchemaKeywords;

/**
 * Whether a step's `$response.body#/pointer` reads something the operation's response actually
 * documents.
 *
 * **The claim is about the DOCUMENT, not about the wire.** An object schema with `properties` and no
 * `additionalProperties: false` permits members it does not name, so a pointer at an undocumented one
 * might still find a value at run time. It is reported anyway, because a workflow output is a promise
 * to a consumer and the document is where that promise lives: a field the response schema does not
 * describe is one no generated client has and no reader can find. Either the pointer is wrong or the
 * field is undocumented, and both are the author's to fix.
 *
 * **Silent wherever the schema cannot refute it.** A response with no schema, an empty one, an object
 * that names no properties, an array with no `items` — each says nothing about the member, and a
 * report there would fire where nothing can be done. Measured before it was written: no schema in the
 * golden corpus is closed, so a rule that only spoke about closed objects would have had zero firings.
 *
 * @internal
 */
final class ResponsePointer
{
    /** `$response.body#/a/b/0/c` — the only expression shape this judges. */
    private const string EXPRESSION = '/^\$response\.body#(\/\S*)$/';

    /** A `$ref` chain longer than this is a schema that refers to itself; stop rather than recurse. */
    private const int MAX_DEPTH = 32;

    /**
     * The pointer the response does not document, or null where it resolves or cannot be judged.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $doc
     */
    public static function undocumented(array $operation, array $doc, string $expression): ?string
    {
        if (preg_match(self::EXPRESSION, $expression, $matches) !== 1) {
            return null;
        }

        $schema = self::successSchema($operation, $doc);

        if ($schema === null) {
            return null;
        }

        $segments = array_map(
            static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            array_slice(explode('/', $matches[1]), 1),
        );

        return self::resolves($schema, $segments, $doc) ? null : $matches[1];
    }

    /**
     * The JSON schema of the operation's one documented success. Null where it documents none, more
     * than one, or no JSON body — in each case nothing here can say what the pointer should find.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>|null
     */
    private static function successSchema(array $operation, array $doc): ?array
    {
        $responses = $operation['responses'] ?? null;

        if (! is_array($responses)) {
            return null;
        }

        $successes = array_filter(
            $responses,
            static fn (mixed $_, int|string $status): bool => preg_match('/^2\d\d$/', (string) $status) === 1,
            ARRAY_FILTER_USE_BOTH,
        );

        if (count($successes) !== 1) {
            return null;
        }

        $response = reset($successes);
        $content = is_array($response) ? ($response['content'] ?? null) : null;
        $json = is_array($content) ? ($content['application/json'] ?? null) : null;
        $schema = is_array($json) ? ($json['schema'] ?? null) : null;

        return is_array($schema) ? self::node($schema) : null;
    }

    /**
     * Whether the schema documents the member the segments name — or cannot say, which answers the same
     * way, because only a document that positively describes the shape may contradict a pointer.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $segments
     * @param  array<string, mixed>  $doc
     */
    private static function resolves(array $schema, array $segments, array $doc, int $depth = 0): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return true;
        }

        $schema = self::dereference($schema, $doc, $depth);

        if ($segments === []) {
            return true;
        }

        $segment = $segments[0];
        $rest = array_slice($segments, 1);

        // Derived from the keyword table rather than listed here, so a position added there is read by
        // this walk the day it lands. `prefixItems` rides along with the branch keywords and costs
        // nothing: its subschemas describe items rather than members, so a NAME segment finds nothing in
        // one — and where it somehow did, the answer is "documented", which only ever makes this quieter.
        foreach (SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST) as $keyword) {
            $branches = $schema[$keyword] ?? null;

            if (is_array($branches) && $branches !== []) {
                // One branch documenting it is enough: a value validating against that branch has it.
                foreach ($branches as $branch) {
                    if (is_array($branch) && self::resolves(self::node($branch), $segments, $doc, $depth + 1)) {
                        return true;
                    }
                }

                return false;
            }
        }

        // An index into a list. Where the schema states no `items`, it describes no member at that
        // position and cannot refute one.
        if (preg_match('/^\d+$/', $segment) === 1 || $segment === '-') {
            $items = $schema['items'] ?? null;

            return ! is_array($items) || self::resolves(self::node($items), $rest, $doc, $depth + 1);
        }

        $properties = $schema['properties'] ?? null;

        if (! is_array($properties) || $properties === []) {
            // Says nothing about its members — an unconstrained object, or a schema that is not one.
            return true;
        }

        $property = $properties[$segment] ?? null;

        return is_array($property) && self::resolves(self::node($property), $rest, $doc, $depth + 1);
    }

    /**
     * A node read out of a document as the JSON object it is. A reader hands back whatever keys were in
     * the file; every position this walks is an object by the schema's own contract.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<string, mixed>
     */
    private static function node(array $node): array
    {
        $out = [];

        foreach ($node as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * The schema a `$ref` names, or the schema itself. Components only: an external pointer names a file
     * no build read, and a schema this cannot see is one it must not contradict.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private static function dereference(array $schema, array $doc, int $depth): array
    {
        $pointer = DocumentGraph::componentRef($schema);

        if ($pointer === null || $depth > self::MAX_DEPTH) {
            return $schema;
        }

        $body = DocumentGraph::componentBody($doc, $pointer);

        return $body === null ? $schema : self::dereference(self::node($body), $doc, $depth + 1);
    }
}

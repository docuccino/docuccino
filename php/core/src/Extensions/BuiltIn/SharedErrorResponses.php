<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Identity\Base32;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\Json;

/**
 * Collapses a repeated error body into shared components, in two independent passes.
 *
 * **Shapes** (`components.schemas`) go first: a body SHAPE two or more operations state identically is
 * hoisted and each `content[<media type>].schema` becomes a `$ref`. This is the pass that decides what a
 * generated client gets — one error type instead of one per operation — and it must not care how an
 * operation ILLUSTRATES the error. `description`, `headers` and the media type's own `example` stay on
 * the operation, because an OAS Reference Object may carry none of them beside a `$ref` (OAS 3.2
 * §4.23.1). The Media Type Object's `example` sits outside the schema and is legal in 3.0, 3.1 and 3.2
 * alike, so nothing is lost and nothing downlevels.
 *
 * **Responses** (`components.responses`) go second, over the rewritten document: a whole response —
 * description, headers, examples, and by now a schema `$ref` — that two or more operations state
 * identically is hoisted too. It runs second so that the response it hoists points at the shared shape
 * instead of carrying its own anonymous copy — a code generator names an inline schema after whatever
 * encloses it, so the wrong order would hand back the per-response types the first pass exists to
 * prevent. The two passes are independent, never alternatives: a response that differs by example simply
 * does not join an identical-response group, while the operations that DO match still share one, and all
 * of them still share one shape.
 *
 * Identity survives both rewrites. Each operation keeps its own response id and provenance beside the
 * `$ref`, and a schema keeps its own, so the id-based semantic diff still sees one response per
 * operation. A hoisted component carries no provenance — that is a per-route fact, and one route's
 * source file has no business speaking for the others.
 *
 * Deliberately narrow: 4xx/5xx only, only bodies that actually repeat, and only responses that carry
 * `content` — a description-only response is already small. Anything already a `$ref` is left alone,
 * which is also what makes a second run over this transformer's own output a no-op.
 */
final class SharedErrorResponses implements DocumentTransformer
{
    /** Below this a response isn't an error, so a shared error shape is none of its business. */
    private const MIN_STATUS = 400;

    /** The provenance key stripped from a hoisted body and kept on the referring node. */
    private const PROVENANCE = 'x-docuccino';

    /**
     * How many occurrences make a body worth hoisting.
     *
     * This threshold is not local, and that is a deliberate, ranked trade rather than an oversight:
     * adding a second identical occurrence promotes the FIRST one from inline to `$ref`, so an operation
     * nobody edited emits different bytes. What it does NOT do is change what anything MEANS — the body
     * is the same body, a generated client mints the same type, and every consumer reads the same
     * contract. That is the whole distinction. The defect this transformer exists to fix is the other
     * kind: a NAME that quietly comes to mean a different shape, which a client keeps compiling against
     * and silently gets wrong. Names here are therefore derived from content alone (see {@see mint()}),
     * while the inline/`$ref` boundary is allowed to move.
     *
     * Hoisting singletons instead would make the boundary local, at the cost of a `components` bucket
     * holding one entry per one-off error body — more indirection, more names to collide, and a worse
     * document for the reader and the code generator both. Repetition is the whole justification for a
     * shared component, so a body that does not repeat does not get one.
     */
    private const MIN_OCCURRENCES = 2;

    /** Characters of the content hash a contested name is discriminated by — 40 bits, as a node id's is. */
    private const DISCRIMINATOR = 8;

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        if (! RepresentationPolicy::fromConfig($context->config->representation)->errorComponents) {
            return;
        }

        $doc = $document->toArray();
        $paths = $doc['paths'] ?? null;
        if (! is_array($paths)) {
            return;
        }

        $components = is_array($doc['components'] ?? null) ? $doc['components'] : [];

        [$paths, $schemas] = self::shareShapes($paths, self::bucket($components, 'schemas'));
        [$paths, $responses] = self::shareResponses($paths, self::bucket($components, 'responses'));

        if ($schemas === null && $responses === null) {
            return;
        }

        if ($schemas !== null) {
            $components['schemas'] = $schemas;
        }

        if ($responses !== null) {
            $components['responses'] = $responses;
        }

        $doc['paths'] = $paths;
        $doc['components'] = $components;

        $document->replace($doc);
    }

    /**
     * @param  array<array-key, mixed>  $components
     * @return array<string, mixed>
     */
    private static function bucket(array $components, string $kind): array
    {
        return is_array($components[$kind] ?? null) ? Arr::stringKeyed($components[$kind]) : [];
    }

    /**
     * Pass one: hoist every repeated body shape, rewriting each media type's `schema` to a `$ref`.
     *
     * @param  array<array-key, mixed>  $paths
     * @param  array<string, mixed>  $existing
     * @return array{array<array-key, mixed>, array<string, mixed>|null}
     */
    private static function shareShapes(array $paths, array $existing): array
    {
        $shapes = self::shareable(self::collect($paths, self::schemaSites(...)));
        if ($shapes === []) {
            return [$paths, null];
        }

        $identity = new IdentityGenerator;
        [$names, $schemas] = self::mint($shapes, $existing, static fn (array $body, string $status): array => [
            self::PROVENANCE => ['id' => $identity->publishedSchemaId($status, Arr::stringKeyed($body))],
        ] + $body);

        return [self::rewrite($paths, $names, self::schemaSites(...), '#/components/schemas/'), $schemas];
    }

    /**
     * Pass two: hoist every response the rewritten document now states identically two or more times.
     *
     * @param  array<array-key, mixed>  $paths
     * @param  array<string, mixed>  $existing
     * @return array{array<array-key, mixed>, array<string, mixed>|null}
     */
    private static function shareResponses(array $paths, array $existing): array
    {
        $responses = self::shareable(self::collect($paths, self::responseSites(...)));
        if ($responses === []) {
            return [$paths, null];
        }

        [$names, $bucket] = self::mint($responses, $existing, static fn (array $body, string $status): array => $body);

        return [self::rewrite($paths, $names, self::responseSites(...), '#/components/responses/'), $bucket];
    }

    /**
     * Every hoistable node of one response, as `[pointer into the response, body]`. A schema pass reads
     * one per media type; a response pass reads the response itself.
     *
     * @param  array<array-key, mixed>  $response
     * @return list<array{list<array-key>, array<array-key, mixed>}>
     */
    private static function schemaSites(array $response): array
    {
        /** @var array<array-key, mixed> $content */
        $content = $response['content'];

        $out = [];
        foreach ($content as $mediaType => $media) {
            $schema = is_array($media) ? ($media['schema'] ?? null) : null;
            if (is_array($schema) && self::isHoistable($schema)) {
                $out[] = [['content', $mediaType, 'schema'], $schema];
            }
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return list<array{list<array-key>, array<array-key, mixed>}>
     */
    private static function responseSites(array $response): array
    {
        return [[[], $response]];
    }

    /**
     * Count what every hoistable node states, keyed by its status and canonical content.
     *
     * @param  array<array-key, mixed>  $paths
     * @param  callable(array<array-key, mixed>): list<array{list<array-key>, array<array-key, mixed>}>  $sites
     * @return array<string, array{status: string, body: array<array-key, mixed>, count: int}>
     */
    private static function collect(array $paths, callable $sites): array
    {
        $out = [];

        foreach ($paths as $operations) {
            if (! is_array($operations)) {
                continue;
            }

            foreach ($operations as $operation) {
                if (! is_array($operation) || ! is_array($operation['responses'] ?? null)) {
                    continue;
                }

                foreach ($operation['responses'] as $status => $response) {
                    if (! is_array($response) || ! self::isShareable($status, $response)) {
                        continue;
                    }

                    foreach ($sites($response) as [, $body]) {
                        $stripped = self::stripProvenance($body);
                        $key = self::key((string) $status, $stripped);

                        $out[$key] ??= ['status' => (string) $status, 'body' => $stripped, 'count' => 0];
                        $out[$key]['count']++;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The bodies worth hoisting: the ones that repeat.
     *
     * @param  array<string, array{status: string, body: array<array-key, mixed>, count: int}>  $bodies
     * @return array<string, array{status: string, body: array<array-key, mixed>, count: int}>
     */
    private static function shareable(array $bodies): array
    {
        return array_filter($bodies, static fn (array $body): bool => $body['count'] >= self::MIN_OCCURRENCES);
    }

    /**
     * The published name of every shared body, plus the bucket it was hoisted into.
     *
     * `Error<status>` belongs to a status only while ONE body claims it. Two retire it and each takes a
     * name discriminated by its own content, so a third arriving later disturbs neither — see
     * {@see ComponentNames} for why a first-come suffix cannot be the answer. A component already
     * holding the plain name with a different body retires it just the same; an identical one is reused,
     * so a rebuild over a restored document stays byte-identical.
     *
     * @param  array<string, array{status: string, body: array<array-key, mixed>, count: int}>  $bodies
     * @param  array<string, mixed>  $existing
     * @param  callable(array<array-key, mixed>, string): array<array-key, mixed>  $publish
     * @return array{array<string, string>, array<string, mixed>}
     */
    private static function mint(array $bodies, array $existing, callable $publish): array
    {
        $bucket = $existing;
        $names = [];

        foreach (self::byStatus($bodies) as $status => $keys) {
            sort($keys);
            $plain = ComponentNames::sanitize('Error'.$status);

            foreach ($keys as $key) {
                $body = $publish($bodies[$key]['body'], (string) $status);

                $name = count($keys) === 1 && self::free($bucket, $plain, $body)
                    ? $plain
                    : self::discriminated($bucket, $plain, $key, $body);

                $bucket[$name] = $body;
                $names[$key] = $name;
            }
        }

        return [$names, $bucket];
    }

    /**
     * @param  array<string, array{status: string, body: array<array-key, mixed>, count: int}>  $bodies
     * @return array<string, list<string>>
     */
    private static function byStatus(array $bodies): array
    {
        $out = [];
        foreach ($bodies as $key => $body) {
            $out[$body['status']][] = $key;
        }

        return $out;
    }

    /**
     * The contested name for one body: the plain name plus a prefix of its content hash, in the same
     * base32 alphabet as a node id and so already `$ref`-safe. Anything else already holding that name
     * pushes it to a numeric suffix — bodies are named in sorted-key order, so which one takes the
     * suffix is a function of the contesting set rather than of the order the document met them.
     *
     * @param  array<string, mixed>  $bucket
     * @param  array<array-key, mixed>  $body
     */
    private static function discriminated(array $bucket, string $plain, string $key, array $body): string
    {
        $base = $plain.'_'.substr(Base32::encode(hash('sha256', $key, binary: true)), 0, self::DISCRIMINATOR);

        $name = $base;
        for ($n = 2; ! self::free($bucket, $name, $body); $n++) {
            $name = $base.'_'.$n;
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @param  array<array-key, mixed>  $body
     */
    private static function free(array $bucket, string $name, array $body): bool
    {
        return ! array_key_exists($name, $bucket) || $bucket[$name] === $body;
    }

    /**
     * Points every shared body at its component, keeping the body's own provenance beside the `$ref` —
     * a per-route fact the hoisted component cannot state.
     *
     * @param  array<array-key, mixed>  $paths
     * @param  array<string, string>  $names
     * @param  callable(array<array-key, mixed>): list<array{list<array-key>, array<array-key, mixed>}>  $sites
     * @return array<array-key, mixed>
     */
    private static function rewrite(array $paths, array $names, callable $sites, string $prefix): array
    {
        foreach ($paths as $path => $operations) {
            if (! is_array($operations)) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (! is_array($operation) || ! is_array($operation['responses'] ?? null)) {
                    continue;
                }

                $responses = $operation['responses'];
                $rewrote = false;

                foreach ($responses as $status => $response) {
                    if (! is_array($response) || ! self::isShareable($status, $response)) {
                        continue;
                    }

                    foreach ($sites($response) as [$pointer, $body]) {
                        $name = $names[self::key((string) $status, self::stripProvenance($body))] ?? null;
                        if ($name === null) {
                            continue;
                        }

                        $reference = ['$ref' => $prefix.$name];
                        if (array_key_exists(self::PROVENANCE, $body)) {
                            $reference = [self::PROVENANCE => $body[self::PROVENANCE]] + $reference;
                        }

                        $response = self::place($response, $pointer, $reference);
                        $rewrote = true;
                    }

                    $responses[$status] = $response;
                }

                if ($rewrote) {
                    $operation['responses'] = $responses;
                    $operations[$method] = $operation;
                    $paths[$path] = $operations;
                }
            }
        }

        return $paths;
    }

    /**
     * Write `$value` at `$pointer` within `$node`; an empty pointer replaces the node itself.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<array-key>  $pointer
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function place(array $node, array $pointer, array $value): array
    {
        if ($pointer === []) {
            return $value;
        }

        $head = array_shift($pointer);

        if ($pointer === []) {
            $node[$head] = $value;

            return $node;
        }

        $child = $node[$head] ?? null;
        if (is_array($child)) {
            $node[$head] = self::place($child, $pointer, $value);
        }

        return $node;
    }

    /**
     * An error response with a real body that isn't already a reference. A response stating BOTH a
     * `$ref` and a body is left alone too: a Reference Object defines no `content`, so whatever built it
     * is saying something this transformer cannot safely rewrite.
     *
     * @param  array-key  $status
     * @param  array<array-key, mixed>  $response
     */
    private static function isShareable(int|string $status, array $response): bool
    {
        return ! isset($response['$ref'])
            && is_array($response['content'] ?? null)
            && $response['content'] !== []
            && ctype_digit((string) $status)
            && (int) $status >= self::MIN_STATUS;
    }

    /**
     * A body worth hoisting: one that states something, and isn't already pointing somewhere else.
     *
     * @param  array<array-key, mixed>  $body
     */
    private static function isHoistable(array $body): bool
    {
        return $body !== [] && ! isset($body['$ref']);
    }

    /**
     * The dedupe identity of a body: its status and everything it states, with provenance already
     * removed and keys sorted so two bodies assembled in different orders still collapse together.
     * List order is NOT normalised — `required: [a, b]` and `required: [b, a]` emit different bytes, so
     * treating them as one body would have to pick which bytes to publish.
     *
     * @param  array<array-key, mixed>  $body
     */
    private static function key(string $status, array $body): string
    {
        return $status."\0".Json::stable($body);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function stripProvenance(array $value): array
    {
        unset($value[self::PROVENANCE]);

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::stripProvenance($v);
            }
        }

        return $value;
    }
}

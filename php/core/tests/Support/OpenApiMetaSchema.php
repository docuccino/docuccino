<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Support;

use Docuccino\Core\Emit\Formats;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\JsonPointer;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;

/**
 * The OpenAPI meta-schemas, vendored per version, as an oracle over emitted bytes: give it a format id
 * and a decoded document and it names every place the document does not answer to its own spec.
 *
 * The vendored files are byte-exact as `spec.openapis.org` serves them at the dated (immutable) URIs in
 * {@see SCHEMAS}; `OpenApiMetaSchemaTest` pins each file's declared id against that URI. None of the
 * three references another document, so nothing here resolves anything off disk or off the network.
 *
 * Two normalisations stand between a vendored file and opis, and both are named where they are applied:
 * {@see dialect()} lifts 3.0's draft-04 to draft-07, and {@see opisWorkarounds()} rewrites the three
 * 2020-12 constructs opis 2.x gets wrong. Each is a documented, bounded edit — never a widening of what
 * a document may contain.
 */
final class OpenApiMetaSchema
{
    /**
     * Format id => [vendored file, the published id the file must declare].
     *
     * Keyed by {@see Formats} id so a new OpenAPI version in that table has to bring a meta-schema with
     * it — `OpenApiMetaSchemaTest` fails when the two sets disagree.
     *
     * @var array<string, array{string, string}>
     */
    public const array SCHEMAS = [
        'openapi-3.2' => ['openapi-v3.2.schema.json', 'https://spec.openapis.org/oas/3.2/schema/2025-09-17'],
        'openapi-3.1' => ['openapi-v3.1.schema.json', 'https://spec.openapis.org/oas/3.1/schema/2022-10-07'],
        'openapi-3.0' => ['openapi-v3.0.schema.json', 'https://spec.openapis.org/oas/3.0/schema/2024-10-18'],
    ];

    /** @var array<string, Validator> */
    private static array $validators = [];

    /** The vendored file for $format. */
    public static function path(string $format): string
    {
        $row = self::SCHEMAS[$format] ?? throw new RuntimeException("No vendored meta-schema for format \"$format\".");

        return dirname(__DIR__).'/Fixtures/'.$row[0];
    }

    /** The dated, immutable URI the vendored file was fetched from. */
    public static function publishedId(string $format): string
    {
        $row = self::SCHEMAS[$format] ?? throw new RuntimeException("No vendored meta-schema for format \"$format\".");

        return $row[1];
    }

    /** The vendored file, decoded to the object graph opis validates against. */
    public static function decode(string $format): mixed
    {
        return json_decode((string) file_get_contents(self::path($format)), flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Every way $instance fails $format's meta-schema, worst-first, one line each:
     * `<data pointer> <keyword>: <message> (schema <schema pointer>)`. Empty means valid.
     *
     * $instance must be an object graph — `json_decode` without `true`, or a kind-preserving YAML parse
     * ({@see EmittedDocument::parseYaml()}). Hand it an associative array and every map in the document
     * reads as a JSON array, which is the blindness this oracle exists to remove.
     *
     * @return list<string>
     */
    public static function findings(string $format, mixed $instance): array
    {
        $result = self::validator($format)->validate($instance, 'https://docuccino.test/'.$format.'.json');

        $error = $result->error();
        if ($error === null) {
            return [];
        }

        $formatter = new ErrorFormatter;
        $findings = [];

        foreach ($formatter->formatKeyed(
            $error,
            static fn (ValidationError $e): string => sprintf(
                '%s: %s (schema %s)',
                $e->keyword(),
                (new ErrorFormatter)->formatErrorMessage($e),
                self::pointer($e->schema()->info()->path()),
            ),
            static fn (ValidationError $e): string => self::pointer($e->data()->fullPath()),
        ) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $findings[] = ($pointer === '' ? '/' : $pointer).' '.$message;
            }
        }

        return $findings;
    }

    /**
     * A JSON pointer a person can read. opis percent-encodes tokens on the way out, which turns the two
     * things a reader navigates by — `$defs` and a templated path segment — into `%24defs` and `%7Bid%7D`.
     *
     * @param  list<int|string>  $path
     */
    private static function pointer(array $path): string
    {
        return rawurldecode(JsonPointer::pathToString($path));
    }

    /** The parsed, cached validator for $format. Parsing a 39KB meta-schema per assertion is the cost. */
    private static function validator(string $format): Validator
    {
        if (isset(self::$validators[$format])) {
            return self::$validators[$format];
        }

        $schema = self::decode($format);
        $schema = str_starts_with(self::publishedId($format), 'https://spec.openapis.org/oas/3.0/')
            ? self::dialect($schema)
            : self::opisWorkarounds($schema);

        $validator = new Validator;
        $validator->setMaxErrors(50);

        // An oracle may not touch what it reads. opis applies schema `default`s INTO the instance, so
        // validating a 3.2 document silently gave it a `jsonSchemaDialect` and a `servers` it never
        // emitted — and the next assertion over the same graph then compared against the mutation.
        $validator->parser()->setOption('allowDefaults', false);

        // opis's own extensions to JSON Schema ($filters, $map, $vars, slots, pragmas, `$data`
        // references). A vendored third-party schema is plain JSON Schema and must be read as such.
        foreach (['allowFilters', 'allowMappers', 'allowTemplates', 'allowGlobals', 'allowSlots', 'allowPragmas', 'allowDataKeyword', 'allowKeywordValidators'] as $extension) {
            $validator->parser()->setOption($extension, false);
        }

        // `format` is an annotation in 2020-12's default vocabulary and OpenAPI declares no
        // format-assertion vocabulary, so asserting it reads a templated server url
        // (`https://api.example.com/{version}`) as a broken uri-reference. opis asserts by default.
        $validator->parser()->setOption('allowFormats', false);

        // opis's `unevaluatedProperties` does not collect annotations produced inside
        // `dependentSchemas` or `if`/`then`, and reports properties the instance does not even carry as
        // unevaluated — a bare `{name, in, schema}` parameter comes back "unevaluated: explode,
        // allowReserved, allowEmptyValue". Off, the oracle stops asserting "no unrecognised member" and
        // keeps every type, required, enum, pattern and oneOf assertion.
        $validator->parser()->setOption('allowUnevaluated', false);

        $validator->resolver()?->registerRaw($schema, 'https://docuccino.test/'.$format.'.json');

        return self::$validators[$format] = $validator;
    }

    /**
     * OpenAPI 3.0's meta-schema is published as draft-04, which opis does not parse. Lifts the dialect
     * WITHOUT touching anything that constrains an instance: the draft-04 `id` anchor is dropped (every
     * `$ref` in the file is a `#/definitions/…` pointer that resolves without it) and the dialect is
     * redeclared. Keys inside a `properties`, `definitions` or `patternProperties` map are names, not
     * keywords, so a property genuinely called `id` survives. Draft-04's boolean
     * `exclusiveMinimum`/`exclusiveMaximum` needs nothing: opis reads it under
     * `allowExclusiveMinMaxAsBool`, which is on by default.
     */
    private static function dialect(mixed $node, bool $inMap = false): mixed
    {
        if (is_array($node)) {
            return array_map(static fn (mixed $v): mixed => self::dialect($v), $node);
        }

        if (! $node instanceof stdClass) {
            return $node;
        }

        $out = new stdClass;
        foreach (get_object_vars($node) as $key => $value) {
            if (! $inMap && ($key === 'id' || $key === '$schema') && is_string($value)) {
                continue;
            }

            $out->{$key} = self::dialect($value, self::opensNameMap($inMap, $key));
        }

        if (! $inMap && isset($node->{'$schema'})) {
            $out->{'$schema'} = 'http://json-schema.org/draft-07/schema#';
        }

        return $out;
    }

    /**
     * The 3.1 and 3.2 meta-schemas are draft 2020-12 and opis 2.x mis-evaluates two of the constructs
     * they lean on, so both are rewritten to the nearest form it reads correctly:
     *
     * - `$dynamicRef: "#meta"` resolves, in opis, to the schema resource ROOT rather than the lexical
     *   `$dynamicAnchor`, so every Schema Object position in the document gets validated against the
     *   OpenAPI Object itself ("required properties (openapi, info) are missing"). Each file carries
     *   exactly one `$dynamicAnchor: "meta"`, at `#/$defs/schema`, and nothing here extends the dialect,
     *   so the static `$ref` is what the dynamic one is specified to resolve to.
     * - `contains` alongside `minContains: 0` still demands one match (`ContainsKeyword` errors on
     *   `$valid === 0` after the `minContains` check has already passed), which fails every document
     *   whose parameter list holds no `querystring` parameter. Dropped with its bounds — the mutual
     *   exclusion of `query` and `querystring` is asserted separately, by a `not`/`allOf` opis reads
     *   correctly, so what goes is the "at most one querystring parameter" cap alone.
     */
    private static function opisWorkarounds(mixed $node, bool $inMap = false): mixed
    {
        if (is_array($node)) {
            return array_map(static fn (mixed $v): mixed => self::opisWorkarounds($v), $node);
        }

        if (! $node instanceof stdClass) {
            return $node;
        }

        $vars = get_object_vars($node);
        $unbounded = ! $inMap && ($vars['minContains'] ?? null) === 0;

        $out = new stdClass;
        foreach ($vars as $key => $value) {
            if (! $inMap && $key === '$dynamicAnchor') {
                continue;
            }

            if (! $inMap && $key === '$dynamicRef' && $value === '#meta') {
                $out->{'$ref'} = '#/$defs/schema';

                continue;
            }

            if ($unbounded && in_array($key, ['contains', 'minContains', 'maxContains'], true)) {
                continue;
            }

            $out->{$key} = self::opisWorkarounds($value, self::opensNameMap($inMap, $key));
        }

        return $out;
    }

    /** Whether $key's value is a map of NAMES (so its keys are never keywords), given where we are. */
    private static function opensNameMap(bool $inMap, string $key): bool
    {
        return ! $inMap && in_array($key, ['properties', 'definitions', 'patternProperties', '$defs'], true);
    }
}

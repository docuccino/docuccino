<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Tests\Support\EmittedDocument;
use Docuccino\Core\Tests\Support\OpenApiMetaSchema;

/**
 * The meta-schema oracle: every OpenAPI artifact the emitters produce, in both serialisations, answers to
 * the published schema for its own version.
 *
 * Nothing else in the suite reads emitted OpenAPI bytes against an external authority — the goldens pin
 * bytes against a committed copy of themselves, and every YAML assertion was a substring, a two-run
 * self-comparison, or a `Yaml::parse()` round trip that collapses map and sequence to the same PHP array
 * and so cannot see kind at all. `--yaml` shipped `paths: []` for an empty `paths` MAP through all of it.
 */

/** @return array<string, array{string, string}> */
function metaSchemaSubjects(): array
{
    $subjects = [];

    foreach (metaSchemaFixtures() as $fixture) {
        foreach (array_keys(OpenApiMetaSchema::SCHEMAS) as $format) {
            $subjects[basename($fixture, '.json').' · '.$format] = [$fixture, $format];
        }
    }

    return $subjects;
}

/**
 * Every UIR fixture in the tree, discovered rather than listed: a fixture added tomorrow is validated
 * without anyone remembering to name it here.
 *
 * @return list<string>
 */
function metaSchemaFixtures(): array
{
    $fixtures = [];

    foreach (glob(dirname(__DIR__).'/Fixtures/*.json') ?: [] as $path) {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (is_array($decoded) && isset($decoded['uir'], $decoded['info'])) {
            $fixtures[] = basename($path);
        }
    }

    sort($fixtures);

    return $fixtures;
}

/**
 * The one spec violation the emitters are KNOWN to pass through, pinned exactly so it cannot grow and
 * cannot quietly be fixed without saying so.
 *
 * The `downlevel` document emitted as 3.0 YAML is the empty-map defect, end to end: the 3.0 emitter drops
 * the `if`/`then`/`else` that 3.0 has no grammar for and leaves the honest unconstrained `{}` behind, and
 * `YamlSerializer` casts every `stdClass` to an array before dumping, so that `{}` is written `[]`. Only
 * the 3.0 meta-schema sees it — 3.1 and 3.2 leave Schema Objects unconstrained — and the JSON emission of
 * the same document is correct, which is what narrows it to the serialiser. Goes when the cast goes.
 *
 * (`postman-surface` declares a server variable with an `enum` and no `default` on purpose, to raise
 * `server.variable-no-default`. That used to be pinned here too: every OpenAPI version requires
 * `default`, and the emitters passed the variable through. They resolve it from its own `enum` now.)
 *
 * @return list<string>
 */
function metaSchemaKnownFindings(string $fixture, string $format, bool $yaml): array
{
    if ($fixture === 'downlevel.uir.json' && $format === 'openapi-3.0' && $yaml) {
        return [
            '/components/schemas/Thing/properties/branch type: The data (array) must match the type: object (schema /definitions/Schema)',
            '/components/schemas/Thing/properties/branch type: The data (array) must match the type: object (schema /definitions/Reference)',
            '/components/schemas/Thing required: The required properties ($ref) are missing (schema /definitions/Reference)',
        ];
    }

    return [];
}

/**
 * The same empty-map defect seen from the other side. {@see metaSchemaKnownFindings()} for what it is;
 * this is the position where the two serialisations of one document disagree about kind, which is the
 * assertion that catches it in EVERY version rather than only in 3.0.
 *
 * @return list<string>
 */
function metaSchemaKnownDifferences(string $fixture, string $format): array
{
    return $fixture === 'downlevel.uir.json' && $format === 'openapi-3.0'
        ? ['/components/schemas/Thing/properties/branch: json is map, yaml is sequence']
        : [];
}

/** @return array{mixed, mixed} the JSON emission and the YAML emission of one document, both as graphs */
function metaSchemaEmissions(string $fixture, string $format): array
{
    $document = UirDocument::fromArray(loadFixture($fixture));

    return [
        json_decode(Formats::emit($format, $document, new EmitOptions)->output, flags: JSON_THROW_ON_ERROR),
        EmittedDocument::parseYaml(Formats::emit($format, $document, (new EmitOptions)->withYaml())->output),
    ];
}

it('emits JSON that answers to its own OpenAPI meta-schema', function (string $fixture, string $format): void {
    [$json] = metaSchemaEmissions($fixture, $format);

    expect(OpenApiMetaSchema::findings($format, $json))->toBe(metaSchemaKnownFindings($fixture, $format, false));
})->with(metaSchemaSubjects());

it('emits YAML that answers to its own OpenAPI meta-schema', function (string $fixture, string $format): void {
    [, $yaml] = metaSchemaEmissions($fixture, $format);

    expect(OpenApiMetaSchema::findings($format, $yaml))->toBe(metaSchemaKnownFindings($fixture, $format, true));
})->with(metaSchemaSubjects());

/**
 * The meta-schemas leave Schema Objects unconstrained in 3.1 and 3.2 (`$defs/schema` is `type: [object,
 * boolean]` and nothing more), so a corrupted `additionalProperties` inside one is invisible to them.
 * This is the assertion that sees it: the same document serialised twice must agree at every position on
 * whether it holds a map, a sequence or a scalar.
 */
it('emits YAML and JSON that agree on every map, sequence and scalar', function (string $fixture, string $format): void {
    [$json, $yaml] = metaSchemaEmissions($fixture, $format);

    expect(EmittedDocument::differences($json, $yaml))->toBe(metaSchemaKnownDifferences($fixture, $format));
})->with(metaSchemaSubjects());

it('vendors a meta-schema for every OpenAPI format the emitters offer', function (): void {
    $emitted = array_values(array_filter(
        Formats::ids(),
        static fn (string $id): bool => str_starts_with($id, 'openapi-'),
    ));

    expect(array_keys(OpenApiMetaSchema::SCHEMAS))->toEqualCanonicalizing($emitted)
        ->and($emitted)->toHaveCount(3);
});

it('pins each vendored meta-schema to the dated URI it was fetched from', function (): void {
    foreach (OpenApiMetaSchema::SCHEMAS as $format => [$file, $published]) {
        $decoded = OpenApiMetaSchema::decode($format);

        expect($decoded)->toBeInstanceOf(stdClass::class);

        // 3.2 and 3.1 are draft 2020-12 (`$id`); 3.0 is draft-04 (`id`).
        $declared = $decoded->{'$id'} ?? $decoded->id ?? null;

        expect($declared)->toBe($published, $file);
    }
});

/**
 * The oracle's own negative path. If these two pass, an empty map written as a sequence is a failure the
 * suite can see — which is the whole reason this file exists.
 */
it('reports an empty map written as a sequence, and nothing when it is written as a map', function (): void {
    $skeleton = <<<'YAML'
        openapi: 3.2.0
        info:
          title: Oracle
          version: 1.0.0
        paths: %s
        YAML;

    $broken = EmittedDocument::parseYaml(sprintf($skeleton, '[]'));
    $sound = EmittedDocument::parseYaml(sprintf($skeleton, '{}'));

    expect(OpenApiMetaSchema::findings('openapi-3.2', $broken))
        ->toBe(['/paths type: The data (array) must match the type: object (schema /$defs/paths)'])
        ->and(OpenApiMetaSchema::findings('openapi-3.2', $sound))->toBe([])
        ->and(EmittedDocument::differences($sound, $broken))->toBe(['/paths: json is map, yaml is sequence'])
        ->and(EmittedDocument::emptyMaps($sound))->toBe(['/paths'])
        ->and(EmittedDocument::emptyMaps($broken))->toBe([]);
});

/**
 * A scan that finds nothing must fail. These are the counts the assertions above are worth: well under
 * what the tree holds today, far enough above zero that a fixture glob which stopped matching, or an
 * emitter that started returning empty output, fails here instead of passing on nothing.
 */
it('validates a plausible minimum of documents and positions', function (): void {
    $documents = 0;
    $positions = 0;

    foreach (metaSchemaSubjects() as [$fixture, $format]) {
        [$json, $yaml] = metaSchemaEmissions($fixture, $format);

        $documents += 2;
        $positions += EmittedDocument::nodes($json) + EmittedDocument::nodes($yaml);
    }

    expect(count(metaSchemaFixtures()))->toBeGreaterThanOrEqual(5)
        ->and($documents)->toBeGreaterThanOrEqual(30)
        ->and($positions)->toBeGreaterThanOrEqual(5000);
});

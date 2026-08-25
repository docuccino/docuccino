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
 *
 * Every subject answers outright — there is no allowance for a violation we already know about. Two were
 * pinned here while they stood: a server variable with no `default`, which every OpenAPI version requires
 * and the emitters passed through, and that empty map. Both are fixed, so an exception list would only be
 * somewhere for the next one to hide. The oracle's own negative path is proved on a hand-built document
 * rather than on a live defect, so nothing was lost by retiring them.
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

    expect(OpenApiMetaSchema::findings($format, $json))->toBe([]);
})->with(metaSchemaSubjects());

it('emits YAML that answers to its own OpenAPI meta-schema', function (string $fixture, string $format): void {
    [, $yaml] = metaSchemaEmissions($fixture, $format);

    expect(OpenApiMetaSchema::findings($format, $yaml))->toBe([]);
})->with(metaSchemaSubjects());

/**
 * The meta-schemas leave Schema Objects unconstrained in 3.1 and 3.2 (`$defs/schema` is `type: [object,
 * boolean]` and nothing more), so a corrupted `additionalProperties` inside one is invisible to them.
 * This is the assertion that sees it: the same document serialised twice must agree at every position on
 * whether it holds a map, a sequence or a scalar.
 */
it('emits YAML and JSON that agree on every map, sequence and scalar', function (string $fixture, string $format): void {
    [$json, $yaml] = metaSchemaEmissions($fixture, $format);

    expect(EmittedDocument::differences($json, $yaml))->toBe([]);
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
 * Counts the schema positions where $matches says yes, walking keyword nodes only — a key inside a
 * `properties`/`$defs`/`patternProperties` map is a NAME, so `{"properties": {"contains": …}}` is a
 * property called `contains` and never the keyword.
 */
function metaSchemaSites(mixed $node, callable $matches, bool $inMap = false): int
{
    if (is_array($node)) {
        return array_sum(array_map(static fn (mixed $v): int => metaSchemaSites($v, $matches), $node));
    }

    if (! $node instanceof stdClass) {
        return 0;
    }

    $vars = get_object_vars($node);
    $found = ! $inMap && $matches($vars) ? 1 : 0;

    foreach ($vars as $key => $value) {
        $names = ! $inMap && in_array($key, ['properties', 'definitions', 'patternProperties', '$defs'], true);
        $found += metaSchemaSites($value, $matches, $names);
    }

    return $found;
}

/**
 * `opisWorkarounds()` drops `contains` with its bounds wherever `minContains: 0` sits beside it, because
 * opis still demands one match. It matches by SHAPE, so a vendored schema that grew a second such site
 * would lose that bound silently and nothing would say so. Exactly one exists — 3.2's "at most one
 * querystring parameter" cap — and none in the other two.
 */
it('drops the contains bound at exactly the one site that has it', function (): void {
    $unbounded = static fn (array $vars): bool => ($vars['minContains'] ?? null) === 0 && isset($vars['contains']);

    $sites = [];
    foreach (array_keys(OpenApiMetaSchema::SCHEMAS) as $format) {
        $sites[$format] = metaSchemaSites(OpenApiMetaSchema::decode($format), $unbounded);
    }

    expect($sites)->toBe(['openapi-3.2' => 1, 'openapi-3.1' => 0, 'openapi-3.0' => 0]);
});

/**
 * The key gates `allowUnevaluated => false` disables, counted so the scope recorded beside that option
 * cannot drift from the files. 3.0 has none — its gates close with `additionalProperties`, which is why
 * it is the strict column of `OpenApiUnevaluatedScopeTest`'s matrix.
 */
it('counts the unevaluatedProperties sites the disabled keyword takes with it', function (): void {
    $closed = static fn (array $vars): bool => ($vars['unevaluatedProperties'] ?? null) === false;

    $sites = [];
    foreach (array_keys(OpenApiMetaSchema::SCHEMAS) as $format) {
        $sites[$format] = metaSchemaSites(OpenApiMetaSchema::decode($format), $closed);
    }

    expect($sites)->toBe(['openapi-3.2' => 28, 'openapi-3.1' => 28, 'openapi-3.0' => 0]);
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
 * An oracle may not touch what it reads. opis applies schema `default`s INTO the instance unless
 * `allowDefaults` is off, so a validated 3.2 document silently gained a `jsonSchemaDialect` and a
 * `servers: [{url: "/"}]` it never emitted — and every assertion after it compared against the mutation
 * rather than the emission. The option is set; this is what proves it still is.
 */
it('leaves the instance it validated exactly as it found it', function (string $fixture, string $format): void {
    [$json] = metaSchemaEmissions($fixture, $format);

    $before = json_encode($json, JSON_THROW_ON_ERROR);

    OpenApiMetaSchema::findings($format, $json);

    expect(json_encode($json, JSON_THROW_ON_ERROR))->toBe($before);
})->with(metaSchemaSubjects());

/**
 * The other half of the oracle's negative path, on a REAL emission. `postman-surface` emits a genuinely
 * null `closedAt` at three example positions, and a member DROPPED at one of them used to read as a member
 * written null — the comparison answered "no differences" while the YAML had lost a member the JSON
 * carries. Nothing else sees it: the meta-schema is asserted here to stay silent on the same mutation,
 * because an example's members are exactly what it leaves unconstrained.
 */
it('sees a null-valued member the YAML dropped, where the meta-schema cannot', function (): void {
    [$json, $yaml] = metaSchemaEmissions('postman-surface.uir.json', 'openapi-3.2');

    $example = $yaml->paths->{'/accounts'}->post->responses->{'201'}->content->{'application/json'}->example;

    expect(property_exists($example, 'closedAt'))->toBeTrue()
        ->and($example->closedAt)->toBeNull()
        ->and(EmittedDocument::differences($json, $yaml))->toBe([]);

    unset($example->closedAt);

    expect(EmittedDocument::differences($json, $yaml))
        ->toBe(['/paths/~1accounts/post/responses/201/content/application~1json/example/closedAt: json carries a member yaml does not'])
        ->and(OpenApiMetaSchema::findings('openapi-3.2', $yaml))->toBe([]);
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

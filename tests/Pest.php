<?php

declare(strict_types=1);
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Docuccino\Laravel\Tests\TestCase;

/*
 * Monorepo Pest bootstrap. Test files live alongside their package
 * (php/<pkg>/tests); this root file binds Pest's test case to them and exposes
 * shared fixture helpers.
 */

uses()->in(dirname(__DIR__).'/php/core/tests');
uses(TestCase::class)->in(dirname(__DIR__).'/php/laravel/tests');

/**
 * Bind the deterministic workbench stub {@see TypeEngine} the Laravel feature tests build against.
 */
function bindStubEngine(): void
{
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
}

/**
 * Build the `default` workbench document, optionally mutating its raw config first. The single
 * shared build helper the Laravel feature tests use instead of each re-rolling the config →
 * generator wiring (and coupling across files via a peer test's global function).
 *
 * @param  callable(array<string, mixed>): array<string, mixed>|null  $mutateConfig
 */
function generateDocument(?callable $mutateConfig = null): GenerationResult
{
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    if ($mutateConfig !== null) {
        $raw = $mutateConfig($raw);
    }

    $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');

    return app(DocumentGenerator::class)->generate($config, app(TypeEngine::class));
}

/**
 * The full round-trip the feature suites lean on: bind the deterministic stub engine, generate the
 * `default` document (optionally mutating its raw config), and return the emitted array — so suites
 * stop each re-rolling the bindStubEngine + generate + toArray wiring (or coupling to a peer test's
 * file-level global).
 *
 * @param  callable(array<string, mixed>): array<string, mixed>|null  $mutateConfig
 * @return array<string, mixed>
 */
function stubDocumentArray(?callable $mutateConfig = null): array
{
    bindStubEngine();

    return generateDocument($mutateConfig)->document->toArray();
}

/**
 * `[the GET /api/forms responses, the schemas the build hoisted, the diagnostics it raised, the whole
 * result]` for one stubbed action return type. The framework-response suites pin a status, a header
 * and — above all — an ABSENT component, so they need the whole response rather than one schema.
 *
 * @param  array<string, ClassMetadata>  $classes  what the engine answers `classMetadata()` with, by FQCN
 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<Diagnostic>, 3: GenerationResult}
 */
function documentForReturn(DType $returnType, array $classes = []): array
{
    $engine = new StubTypeEngine(
        analyses: [
            'Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(
                returns: [new ReturnSite($returnType, new SourceLocation(''))],
            ),
        ],
        classes: $classes,
    );
    app()->instance(TypeEngine::class, $engine);

    $result = generateDocument();
    $document = $result->document->toArray();

    return [
        $document['paths']['/api/forms']['get']['responses'] ?? [],
        $document['components']['schemas'] ?? [],
        $result->diagnostics,
        $result,
    ];
}

/** The plain type→schema chain the validation suites convert against: the core mappers, no engine. */
function schemaConverter(): SchemaConverter
{
    return new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);
}

/**
 * A rule set through the shared validation chain, as a JSON Schema object — the same normalise → order →
 * convert sequence {@see DataRequestExtension} runs. `normalize: false` gives the un-normalised set, which
 * is what {@see RuleSetNormalizer} exists to prevent reaching the chain.
 *
 * @return array<string, mixed>
 */
function validationSchema(RuleSet $rules, SchemaConverter $context, bool $normalize = true): array
{
    $ordered = (new RuleOrdering)->order($normalize ? (new RuleSetNormalizer)->normalize($rules) : $rules);

    return (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))->convert($ordered, $context)->schema;
}

/**
 * Index a list of {@see QueryParameterSpec} by parameter name
 * — the shared helper the query-builder and json-api-paginate parameter unit suites both need
 * (promoted here so neither couples to the other's file-level global).
 *
 * @param  list<QueryParameterSpec>  $specs
 * @return array<string, QueryParameterSpec>
 */
function specsByName(array $specs): array
{
    $byName = [];
    foreach ($specs as $spec) {
        $byName[$spec->name] = $spec;
    }

    return $byName;
}

/**
 * Index an emitted operation's parameters by name (the shape every parameter-asserting feature test
 * needs). Promoted here so suites don't couple to a peer test's file-level global.
 *
 * @return array<string, array<string, mixed>>
 */
function paramsByName(array $operation): array
{
    $byName = [];
    foreach ($operation['parameters'] ?? [] as $parameter) {
        $byName[$parameter['name']] = $parameter;
    }

    return $byName;
}

/**
 * A response as documented, following a `$ref` into `components.responses`. An error body repeated across
 * operations is hoisted to a shared component (see {@see SharedErrorResponses}), so a test asserting on the
 * body has to resolve the reference; the operation's own `x-docuccino` wins over the component's, since
 * provenance stays per-route.
 *
 * @param  array<string, mixed>  $document  the emitted document
 * @return array<string, mixed>
 */
function resolveResponse(array $document, mixed $response): array
{
    if (! is_array($response)) {
        return [];
    }

    $prefix = '#/components/responses/';
    $ref = $response['$ref'] ?? null;
    if (! is_string($ref) || ! str_starts_with($ref, $prefix)) {
        return $response;
    }

    $component = $document['components']['responses'][substr($ref, strlen($prefix))] ?? null;

    return is_array($component) ? $response + $component : $response;
}

/**
 * A schema as documented, following a `$ref` into `components.schemas`. An error SHAPE repeated across
 * operations is hoisted to a shared component (see {@see SharedErrorResponses}), so a test asserting on
 * the shape has to resolve the reference; members stated beside the `$ref` win.
 *
 * @param  array<string, mixed>  $document  the emitted document
 * @return array<string, mixed>
 */
function resolveSchema(array $document, mixed $schema): array
{
    if (! is_array($schema)) {
        return [];
    }

    $prefix = '#/components/schemas/';
    $ref = $schema['$ref'] ?? null;
    if (! is_string($ref) || ! str_starts_with($ref, $prefix)) {
        return $schema;
    }

    $component = $document['components']['schemas'][substr($ref, strlen($prefix))] ?? null;

    return is_array($component) ? $schema + $component : $schema;
}

/**
 * One media type of the workbench form route's response at `$status`, through {@see resolveResponse()} —
 * the shape most error-response assertions want. Its `schema` is left as documented, so a test that
 * cares which component it points at can still see the `$ref`; {@see errorSchemaOf()} reads through it.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function mediaOf(array $document, string $status, string $mediaType, string $path = '/api/forms/{form}', string $method = 'get'): array
{
    $response = $document['paths'][$path][$method]['responses'][$status] ?? [];
    $media = resolveResponse($document, $response)['content'][$mediaType] ?? [];

    return is_array($media) ? $media : [];
}

/**
 * The body shape one operation's error response states, with both hoists resolved — the shared error
 * shape may sit in `components.schemas` and the response itself in `components.responses`.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function errorSchemaOf(array $document, string $status, string $mediaType, string $path = '/api/forms/{form}', string $method = 'get'): array
{
    return resolveSchema($document, mediaOf($document, $status, $mediaType, $path, $method)['schema'] ?? null);
}

/**
 * The schema components a document hoisted for the types its routes name, with the shared error shapes
 * {@see SharedErrorResponses} lifts out of the framework's own 4xx excluded — those belong to the error
 * contract rather than to whatever a route returns.
 *
 * @param  array<string, mixed>  $components
 * @return array<string, mixed>
 */
function typeSchemas(array $components): array
{
    return array_filter(
        $components,
        static fn (string $name): bool => preg_match('/^Error\d{3}(_[a-z2-7]+)?$/', $name) !== 1,
        ARRAY_FILTER_USE_KEY,
    );
}

/**
 * @return array<string, mixed>
 */
function loadFixture(string $name): array
{
    $path = dirname(__DIR__).'/php/core/tests/Fixtures/'.$name;
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Fixture not found: '.$path);
    }

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

/**
 * @return array<string, mixed>
 */
function workedExample(): array
{
    return loadFixture('worked-example.json');
}

/**
 * A maximal valid UIR document exercising every modelled surface (requestBody, securitySchemes +
 * operation-level security, servers with variables, webhooks, path/header/cookie params, the 3.2
 * `query` method, multiple media types, examples, externalDocs, deprecated, content pages, floats
 * and unicode keys).
 *
 * @return array<string, mixed>
 */
function kitchenSink(): array
{
    return loadFixture('kitchen-sink.uir.json');
}

/**
 * Reads a committed golden artifact byte-for-byte.
 */
function loadGolden(string $name): string
{
    $path = dirname(__DIR__).'/php/core/tests/Fixtures/golden/'.$name;
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Golden not found: '.$path);
    }

    return $contents;
}

/**
 * Path of a committed Laravel-adapter golden (shared by ExportTest/ContentTest).
 */
function golden(string $name): string
{
    return dirname(__DIR__).'/php/laravel/tests/Fixtures/golden/'.$name;
}

/**
 * Reads a golden, or (under DOCUCCINO_UPDATE_GOLDEN=1) writes the freshly generated bytes first.
 */
function assertGolden(string $name, string $actual): void
{
    $path = golden($name);
    if (getenv('DOCUCCINO_UPDATE_GOLDEN') === '1') {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $actual);
    }

    expect($actual)->toBe(file_get_contents($path));
}

/**
 * A rich base document (extends the design §11 worked example): one operation with a path param,
 * a query param carrying an enum, a JSON response with an object schema, an error response, a
 * named component schema, and a content page — enough surface to exercise every diff rule.
 *
 * @return array<string, mixed>
 */
function diffBase(): array
{
    return [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms API', 'version' => '1.0.0'],
        'paths' => [
            '/api/v1/forms/{id}' => [
                'get' => [
                    'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
                    'operationId' => 'forms.show',
                    'summary' => 'Show a form',
                    'tags' => ['Forms'],
                    'parameters' => [
                        [
                            'x-docuccino' => ['id' => 'par:v1:bbbbbbbbbbbbbbbb'],
                            'name' => 'id', 'in' => 'path', 'required' => true,
                            'schema' => ['type' => 'integer'],
                        ],
                        [
                            'x-docuccino' => ['id' => 'par:v1:cccccccccccccccc'],
                            'name' => 'status', 'in' => 'query', 'required' => false,
                            'schema' => ['type' => 'string', 'enum' => ['draft', 'published', 'archived']],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'x-docuccino' => ['id' => 'res:v1:dddddddddddddddd'],
                            'description' => 'The form',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'id' => ['type' => 'integer'],
                                            'title' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '404' => ['description' => 'Not found'],
                    ],
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'FormData' => [
                    'x-docuccino' => ['id' => 'sch:v1:eeeeeeeeeeeeeeee'],
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                ],
            ],
        ],
        'x-docuccino' => [
            'content' => [
                'pages' => [
                    ['id' => 'page:v1:ffffffffffffffff', 'slug' => 'getting-started', 'title' => 'Getting started', 'content' => 'Welcome.'],
                ],
            ],
        ],
    ];
}

/**
 * Recursively strips every `x-docuccino` member and the UIR-only top-level `$schema`/`uir`, so the
 * remainder is exactly what a lossless OAS 3.2 transcode must equal.
 *
 * @param  array<string, mixed>  $node
 * @return array<string, mixed>
 */
function stripDocuccino(array $node): array
{
    unset($node['x-docuccino']);

    $out = [];
    foreach ($node as $key => $value) {
        $key = (string) $key;
        $out[$key] = str_starts_with($key, 'x-') ? $value : stripDocuccinoRecursive($value);
    }

    return $out;
}

function stripDocuccinoRecursive(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map(stripDocuccinoRecursive(...), $value);
    }

    return stripDocuccino($value);
}

/**
 * Gate for the fixture-app-dependent integration tests. Normally a missing
 * fixture app skips the test (contributors without it still get a green local
 * run). Under `DOCUCCINO_REQUIRE_FIXTURE=1` — the CI fixture job — a missing
 * fixture app is instead a hard FAILURE, so the suite can never silently pass by
 * skipping the very tests the job exists to run.
 */
function ensureFixtureAvailable(bool $available): void
{
    if ($available) {
        return;
    }

    if (getenv('DOCUCCINO_REQUIRE_FIXTURE') === '1') {
        throw new RuntimeException(
            'Fixture app required (DOCUCCINO_REQUIRE_FIXTURE=1) but absent — provision per tests/fixture-app/setup.md',
        );
    }

    test()->markTestSkipped('fixture app absent — recreate per tests/fixture-app/setup.md');
}

/**
 * A generation result's diagnostics with one code, in order — the shape every diagnostic-asserting
 * suite needs.
 *
 * @param  list<Diagnostic>  $diagnostics
 * @return list<Diagnostic>
 */
function diagnosticsCoded(array $diagnostics, string $code): array
{
    return array_values(array_filter(
        $diagnostics,
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === $code,
    ));
}

/**
 * A package's `src/` imports matching a pattern, as `relative/path.php: FQCN` strings.
 *
 * The boundary escape hatch for dependencies Pest's arch layers cannot see: a layer is resolved
 * through composer's PSR-4 prefixes, so phpstan/phpstan — a phar with no prefix — is invisible to
 * `not->toUse('PHPStan')`, which then passes vacuously. Scanning the imports is the honest test.
 *
 * @return list<string>
 */
function importsMatching(string $package, string $pattern): array
{
    $src = dirname(__DIR__).'/php/'.$package.'/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

    $found = [];
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all('/^use\s+(?!function\s|const\s)([^\s;]+)/m', (string) file_get_contents($file->getPathname()), $matches);
        foreach ($matches[1] as $import) {
            if (preg_match($pattern, $import) === 1) {
                $found[] = str_replace($src.'/', '', $file->getPathname()).': '.$import;
            }
        }
    }

    sort($found);

    return $found;
}

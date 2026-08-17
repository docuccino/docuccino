<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallableRef;
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
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\FragmentCacheDirs;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Docuccino\Laravel\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

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
 * Build the `default` workbench document, optionally mutating its raw config first. The one shared
 * build helper the Laravel feature tests use, so none of them re-rolls the config → generator wiring
 * or reaches for a peer test's file-level function.
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
 * `default` document (optionally mutating its raw config), and return the emitted array — so no suite
 * re-rolls the bindStubEngine + generate + toArray wiring.
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
 * `[the GET /api/forms responses, the whole document, the diagnostics it raised, the whole result]` for
 * one stubbed action return type. The framework-response suites pin a status, a header and — above all
 * — an ABSENT component, so they need the whole response rather than one schema, and the whole document
 * rather than one bucket ({@see typeSchemas()} tells a hoisted type from a shared error shape by where
 * the document uses it).
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
        $document,
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
 * Index a list of {@see QueryParameterSpec} by parameter name — the shared helper the query-builder
 * and json-api-paginate parameter unit suites both need.
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
 * Index an emitted operation's parameters by name — the shape every parameter-asserting feature test
 * needs.
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
 * {@see SharedErrorResponses} lifts out of a repeated 4xx excluded — those belong to the error contract
 * rather than to whatever a route returns.
 *
 * Told apart by where the document USES them and never by what they are called: a shared error shape is
 * one reached only through an error response. Filtering on the name instead would hide an application
 * class genuinely called `NotFound` or `Conflict` from every assertion built on this helper, which is
 * exactly the hoist those assertions exist to catch.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function typeSchemas(array $document): array
{
    $errors = [];
    $elsewhere = $document;

    foreach ($document['paths'] ?? [] as $path => $operations) {
        foreach (is_array($operations) ? $operations : [] as $method => $operation) {
            foreach ((is_array($operation) ? $operation['responses'] ?? [] : []) as $status => $response) {
                if (ctype_digit((string) $status) && (int) $status >= 400) {
                    $errors[] = $response;
                    unset($elsewhere['paths'][$path][$method]['responses'][$status]);
                }
            }
        }
    }

    // A shared error RESPONSE is reached only through those, and the shape it points at only through it.
    foreach (componentRefsIn($errors, 'responses') as $name) {
        $errors[] = $document['components']['responses'][$name] ?? [];
        unset($elsewhere['components']['responses'][$name]);
    }

    $used = componentRefsIn($elsewhere, 'schemas');

    return array_filter(
        $document['components']['schemas'] ?? [],
        static fn (string $name): bool => ! in_array($name, componentRefsIn($errors, 'schemas'), true)
            || in_array($name, $used, true),
        ARRAY_FILTER_USE_KEY,
    );
}

/**
 * Every `#/components/{$bucket}/…` name referenced anywhere under `$node`.
 *
 * @return list<string>
 */
function componentRefsIn(mixed $node, string $bucket): array
{
    if (! is_array($node)) {
        return [];
    }

    $prefix = '#/components/'.$bucket.'/';

    $out = [];
    foreach ($node as $key => $value) {
        if ($key === '$ref' && is_string($value) && str_starts_with($value, $prefix)) {
            $out[] = substr($value, strlen($prefix));

            continue;
        }

        $out = [...$out, ...componentRefsIn($value, $bucket)];
    }

    return $out;
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
 * One named path parameter of an emitted operation, or null when the operation has no such parameter.
 *
 * @param  array<string, mixed>  $operation
 * @return array<string, mixed>|null
 */
function pathParameter(array $operation, string $name): ?array
{
    /** @var list<array<string, mixed>> $parameters */
    $parameters = $operation['parameters'] ?? [];

    foreach ($parameters as $parameter) {
        if (($parameter['in'] ?? null) === 'path' && ($parameter['name'] ?? null) === $name) {
            return $parameter;
        }
    }

    return null;
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

/**
 * One schema's claim on a component name, as ComponentNames reads it: the name it asked for,
 * the identity behind it, and the bytes it publishes — the last standing in for the identity a schema
 * that names none doesn't have.
 *
 * @return array{base: string, identity: string|null, content: string}
 */
function claim(string $base, ?string $identity, string $content = '{"type":"object"}'): array
{
    return ['base' => $base, 'identity' => $identity, 'content' => $content];
}

/*
 * ---------------------------------------------------------------------------
 * Locality harnesses
 * ---------------------------------------------------------------------------
 *
 * Determinism — the same code emits the same bytes — is only half the promise. The other half is
 * LOCALITY: adding, removing or reordering one route may add and remove operations, and may never
 * change the emitted representation of a route it did not touch. A build can be perfectly repeatable
 * and still fail that, and a build that fails it is green.
 *
 * The two harnesses below are the guard. They own their route set — the workbench's is the goldens',
 * so a harness that added to it would move a golden to prove a point about something else.
 */

/**
 * Reset the router to exactly the routes a row declares, and bind the analyser it builds against.
 *
 * @param  callable(Router): void  $routes
 * @param  callable(): TypeEngine|null  $engine  a FRESH engine per build (the harnesses count calls)
 */
function localityBuild(callable $routes, ?callable $engine = null, ?TypeEngine &$bound = null): GenerationResult
{
    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $routes($router);

    $bound = new CountingTypeEngine(($engine ?? static fn (): TypeEngine => WorkbenchEngine::make())());
    app()->instance(TypeEngine::class, $bound);

    return generateDocument();
}

/**
 * A generation result as CANONICAL bytes, decoded back to an array.
 *
 * Read through the emitter rather than `toArray()` on purpose: `components.schemas` keeps INSERTION
 * order there, which legitimately differs between two builds and is sorted away only by the
 * canonicalizer. Comparing `toArray()` would fail on order alone.
 *
 * @return array<string, mixed>
 */
function emittedArray(GenerationResult $result): array
{
    /** @var array<string, mixed> $document */
    $document = json_decode((new UirEmitter)->emit($result->document), true, flags: JSON_THROW_ON_ERROR);

    return $document;
}

/**
 * Every `#/components/...` pointer a node reaches, transitively. A locality break usually shows up as
 * a component moving under an operation's feet rather than in the operation node itself, so "this
 * route's emitted representation" has to mean the closure, not just the node.
 *
 * @param  array<string, mixed>  $document
 * @param  array<string, mixed>  $seen
 * @return array<string, mixed> pointer => component node, sorted
 */
function referencedComponents(array $document, mixed $node, array $seen = []): array
{
    foreach (pointersIn($node) as $pointer) {
        if (array_key_exists($pointer, $seen)) {
            continue;
        }

        [, , $bucket, $name] = explode('/', $pointer);
        $components = $document['components'][$bucket] ?? [];

        // A dangling reference is a defect in its own right, and silently recording null for one
        // would let two builds agree by both being broken.
        expect($components)->toBeArray()->toHaveKey($name);

        $seen[$pointer] = $components[$name];
        $seen = referencedComponents($document, $components[$name], $seen);
    }

    ksort($seen);

    return $seen;
}

/**
 * The `#/components/...` pointers stated anywhere under a node.
 *
 * A `security` requirement names its scheme as a KEY and not through a `$ref`, so a `$ref`-only walk
 * leaves both the scheme definition and every change to it outside the subject's projection — and a
 * first-come `components.securitySchemes` name goes uncaught. It is a component the operation depends
 * on by name, so it is collected as one.
 *
 * @return list<string>
 */
function pointersIn(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }

    $found = [];
    foreach ($node as $key => $value) {
        if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/components/')) {
            $found[] = $value;

            continue;
        }

        if ($key === 'security' && is_array($value)) {
            foreach ($value as $requirement) {
                foreach (is_array($requirement) ? array_keys($requirement) : [] as $scheme) {
                    $found[] = '#/components/securitySchemes/'.$scheme;
                }
            }

            continue;
        }

        $found = array_merge($found, pointersIn($value));
    }

    return $found;
}

/**
 * One operation's emitted representation as bytes: its own canonical node plus every component it
 * transitively `$ref`s. `$subject` is a `METHOD /path` pair.
 */
function operationBytes(GenerationResult $result, string $subject): string
{
    [$method, $path] = explode(' ', $subject, 2);
    $method = strtolower($method);

    $document = emittedArray($result);
    $paths = $document['paths'] ?? [];

    // The subject has to BE there — a projection of a missing operation compares equal to itself.
    expect($paths)->toBeArray()->toHaveKey($path);
    expect($paths[$path])->toBeArray()->toHaveKey($method);

    $operation = $paths[$path][$method];

    return (string) json_encode(
        ['operation' => $operation, 'components' => referencedComponents($document, $operation)],
        JSON_THROW_ON_ERROR,
    );
}

/**
 * Diagnostics as comparable data. Codes alone would miss a message that names the wrong class, and
 * the list is already deterministically ordered.
 *
 * @param  list<Diagnostic>  $diagnostics
 * @return list<array<string, mixed>>
 */
function diagnosticRecords(array $diagnostics): array
{
    return array_map(static fn (Diagnostic $d): array => $d->toArray(), $diagnostics);
}

/**
 * LOCALITY. Build `$baseline`, then `$baseline` + `$extra`, and hold `$subject` — an operation the
 * extra route has nothing to do with — to byte-identical output across the two.
 *
 * @param  callable(Router): void  $baseline
 * @param  callable(Router): void  $extra  routes added beside the baseline, never touching the subject
 * @param  string  $subject  `METHOD /path` of the operation that must not move
 * @param  callable(): TypeEngine|null  $engine
 */
function assertUnaffectedByUnrelatedRoute(callable $baseline, callable $extra, string $subject, ?callable $engine = null): void
{
    $before = localityBuild($baseline, $engine);
    $after = localityBuild(static function (Router $router) use ($baseline, $extra): void {
        $baseline($router);
        $extra($router);
    }, $engine);

    // A row whose extra route changes nothing at all is decoration: it would stay green with the
    // whole naming layer deleted. The extra route must reach the build somehow — as output, or as a
    // diagnostic when OpenAPI has no slot for what it asked for.
    expect([(new UirEmitter)->emit($after->document), diagnosticRecords($after->diagnostics)])
        ->not->toBe([(new UirEmitter)->emit($before->document), diagnosticRecords($before->diagnostics)]);

    expect(operationBytes($after, $subject))->toBe(operationBytes($before, $subject));
}

/**
 * Point the fragment cache at a fresh directory and return it. Enables the cache too — a row that set
 * only the path would document itself against a disabled store and prove nothing.
 */
function fragmentCacheDir(string $slug): string
{
    $dir = sys_get_temp_dir().'/docuccino-'.$slug.'-'.uniqid('', true);
    FragmentCacheDirs::record($slug, $dir);

    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', $dir);

    return $dir;
}

/** Take a fragment-cache directory away again, whether or not the row that used it passed. */
function removeFragmentCacheDir(string $dir): void
{
    array_map('unlink', glob($dir.'/*') ?: []);
    @unlink($dir.'/.gitignore');
    @rmdir($dir);
}

/**
 * The same for every directory a slug produced IN THIS PROCESS — for a suite sweeping up in `afterEach`.
 * Never the slug's whole glob: two suites share the `warm`/`cold` slugs, and under Paratest that would
 * be one worker deleting a directory another is mid-build against ({@see FragmentCacheDirs}).
 */
function removeFragmentCacheDirs(string $slug): void
{
    foreach (FragmentCacheDirs::take($slug) as $dir) {
        removeFragmentCacheDir($dir);
    }
}

/**
 * WARM == COLD. Warm the fragment cache on `$before`, document `$after` against that warm cache, then
 * document `$after` again in a fresh cache directory, and hold the two to the same bytes AND the same
 * diagnostics.
 *
 * Both directions of the diagnostic comparison matter: a warm build reporting FEWER diagnostics is
 * the silent-degradation form, and one reporting MORE is just as wrong.
 *
 * Hands back the WARM result, so a caller can go on to pin what that build actually published —
 * equal-and-both-empty is equality that proves nothing.
 *
 * @param  callable(Router): void  $before  the route set the cache is warmed on
 * @param  callable(Router): void  $after  the route set both builds document
 * @param  callable(): TypeEngine|null  $engine
 */
function assertWarmEqualsCold(callable $before, callable $after, ?callable $engine = null): GenerationResult
{
    $warmDir = fragmentCacheDir('warm');
    $coldDir = null;

    try {
        localityBuild($before, $engine);

        // An unwritten cache makes the "warm" build a second cold one, and the row proves nothing.
        expect(glob($warmDir.'/*.json') ?: [])->not->toBeEmpty();

        $warm = localityBuild($after, $engine, $warmEngine);

        $coldDir = fragmentCacheDir('cold');
        $cold = localityBuild($after, $engine, $coldEngine);

        // A build that documented nothing would agree with any other build that documented nothing.
        expect($warm->document->toArray())->toHaveKey('paths')
            ->and($warm->document->toArray()['paths'])->not->toBeEmpty()
            ->and((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
            ->and(diagnosticRecords($warm->diagnostics))->toBe(diagnosticRecords($cold->diagnostics));

        // …and the warm build really was warm. Every row shares at least one route with `$before`, so
        // it must reach the engine strictly less often than the cold build does.
        expect($warmEngine)->toBeInstanceOf(CountingTypeEngine::class)
            ->and($coldEngine)->toBeInstanceOf(CountingTypeEngine::class)
            ->and($warmEngine->analyzeCount)->toBeLessThan($coldEngine->analyzeCount);

        return $warm;
    } finally {
        removeFragmentCacheDir($warmDir);
        if ($coldDir !== null) {
            removeFragmentCacheDir($coldDir);
        }
    }
}

/**
 * Register a render callback on the booted exception handler and return the `CallableRef::symbol()` the
 * inferred-handler tier will analyse it under, so a stub engine can be scripted for exactly that key.
 */
function registerRenderCallback(Closure $callback, string $exceptionType): string
{
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable($callback);

    $function = new ReflectionFunction($callback);

    return (new CallableRef(
        (string) $function->getFileName(),
        null,
        null,
        $function->getStartLine(),
        $function->getParameters()[0]->getName(),
        $exceptionType,
    ))->symbol();
}

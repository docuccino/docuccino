<?php

declare(strict_types=1);

use Docuccino\Core\Contract\CheckResult;
use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Exchange;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Draft\SchemaDraft;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Commands\WatchCommand;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Integrations\QueryBuilder\ListValueDescriber;
use Docuccino\Laravel\Integrations\Support\QueryParameterSpec;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Testing\ApiContract;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Almanac;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\FragmentCacheDirs;
use Docuccino\Laravel\Tests\Support\ScriptedBuildRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Docuccino\Laravel\Tests\TestCase;
use Docuccino\Laravel\Watch\BuildRunner;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;

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
 * @param  ?callable(TraceVisitor): void  $trace  a scripted walk over the action, for the suites whose
 *                                                fact lives at the CALL that built the response rather
 *                                                than in its type
 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<Diagnostic>, 3: GenerationResult}
 */
function documentForReturn(DType $returnType, array $classes = [], ?callable $trace = null): array
{
    $action = 'Workbench\\App\\Http\\Controllers\\FormController::index';

    $engine = new StubTypeEngine(
        analyses: [
            $action => new ActionAnalysis(
                returns: [new ReturnSite($returnType, new SourceLocation(''))],
            ),
        ],
        classes: $classes,
        traces: $trace === null ? [] : [$action => $trace],
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

/**
 * A schema draft with `$standing` written keyword by keyword, then `$declaration` written as one
 * declared shape ({@see SchemaDraft::declareShape()}), frozen and stripped of its provenance — so a
 * test reads exactly what the document would publish.
 *
 * @param  array<string, mixed>  $standing
 * @param  array<string, mixed>  $declaration
 * @return array<string, mixed>
 */
function frozenShape(array $standing, array $declaration, ?Contribution $standingBy = null, ?Contribution $by = null): array
{
    $draft = new SchemaDraft;

    foreach ($standing as $keyword => $value) {
        $draft->set($keyword, $value, $standingBy ?? Contribution::inference());
    }

    $draft->declareShape($declaration, $by ?? Contribution::attribute());

    $frozen = $draft->freeze()->toArray();
    unset($frozen['x-docuccino']);

    return $frozen;
}

/** The plain type→schema chain the validation suites convert against: the core mappers, no engine. */
function schemaConverter(): SchemaConverter
{
    return new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);
}

/**
 * A schema a conversion handed back as a `$ref`, resolved through that converter's own registry: the
 * name the document would publish it under, and the body. A paginated envelope hoists to a component
 * of its own, so a test asserting on the envelope's SHAPE reads it here rather than off the result.
 *
 * The name is the settled one, never the registration slot — a slot is first-come and a test pinned to
 * one would pass while the document published something else.
 *
 * @param  array<string, mixed>  $schema
 * @return array{string, array<string, mixed>}
 */
function convertedComponent(SchemaConverter $converter, array $schema): array
{
    $prefix = '#/components/schemas/';
    expect($schema['$ref'] ?? null)->toBeString()->toStartWith($prefix);

    $slot = substr((string) $schema['$ref'], strlen($prefix));
    $registry = $converter->components();

    expect($registry->schemas())->toHaveKey($slot);

    return [$registry->schemaRenames()[$slot] ?? $slot, $registry->schemas()[$slot]];
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
 * The Almanac fixture's model-prose lookups — its relation docblocks and the `@property` summaries an
 * engine would have recovered. Shared by the describer's own suite and the parameter suite that reads
 * through it, so both ask exactly the same model the same questions.
 */
function almanacDescriber(): ListValueDescriber
{
    return new ListValueDescriber(Almanac::class, new ClassMetadata(Almanac::class, [
        new PropertyMetadata('title', new UnknownT('test'), 'The almanac\'s display title.'),
        new PropertyMetadata('issued_at', new UnknownT('test')),
    ]));
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
 * Replaces `x-docuccino.generator.version` with a placeholder — the ONE member of an emitted document
 * that tracks the release rather than the application it documents.
 *
 * Byte-locking it would make bumping `DocuccinoServiceProvider::VERSION` a mass golden regeneration,
 * and a regeneration diff nobody reads is exactly where a real drift hides.
 * So the version travels honestly into every emitted document (and into the fragment cache's tool
 * version, which is why an upgrade cannot serve an older release's fragments) and the golden
 * COMPARISON looks past it. Applied to both sides; never to what a regeneration writes, so the
 * goldens on disk keep the real version they were recorded with. Nothing else is normalised —
 * `info.version`, `specVersion` and `contentHash` are all still byte-locked.
 */
function withoutGeneratorVersion(string $document): string
{
    return (string) preg_replace(
        '/("generator"\s*:\s*\{[^{}]*"version"\s*:\s*)"[^"]*"/',
        '${1}"@generator-version@"',
        $document,
    );
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

    expect(withoutGeneratorVersion($actual))->toBe(withoutGeneratorVersion((string) file_get_contents($path)));
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
 * A package's `src/` references matching a pattern, as `relative/path.php: FQCN` strings.
 *
 * The boundary escape hatch for dependencies Pest's arch layers cannot see: a layer is resolved
 * through composer's PSR-4 prefixes, so phpstan/phpstan — a phar with no prefix — is invisible to
 * `not->toUse('PHPStan')`, which then passes vacuously. Scanning the source is the honest test.
 *
 * @return list<string>
 */
function importsMatching(string $package, string $pattern): array
{
    return referencesIn(dirname(__DIR__).'/php/'.$package.'/src', $pattern);
}

/**
 * The same scan over any directory of PHP sources: every namespaced name they NAME IN CODE, matched
 * against $pattern without its leading backslash, as sorted `relative/path.php: FQCN` strings.
 *
 * It tokenises rather than greps because a guard must read the same grammar as the thing it guards,
 * and `^use …` over lines reads a narrower one than PHP does — a fully-qualified reference in an
 * expression, an attribute or a type position (`\PHPStan\Foo::class`, `new \Larastan\Bar`) walks
 * straight past it. Tokenising also draws the string/comment line for free, which is the point:
 * naming a class in a STRING is the sanctioned way to probe for an optional package
 * (`EnginePackage::BUILDER`), so it must not be flagged. Doc-comment types are out of scope for the
 * same reason — they load nothing, and PHPStan already fails on one it cannot resolve.
 *
 * @return list<string>
 */
function referencesIn(string $directory, string $pattern): array
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    $found = [];
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (namesInSource((string) file_get_contents($file->getPathname())) as $name) {
            if (preg_match($pattern, $name) === 1) {
                $found[str_replace($directory.'/', '', $file->getPathname()).': '.$name] = true;
            }
        }
    }

    $names = array_keys($found);
    sort($names);

    return $names;
}

/**
 * Every namespaced name one PHP source names in code, once each and without a leading backslash:
 * `use` imports (grouped ones expanded) and inline references alike. The `namespace` declaration is
 * not one of them — a file does not import itself.
 *
 * @return list<string>
 */
function namesInSource(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $names = [];
    $declaringNamespace = false;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (! is_array($token)) {
            continue;
        }

        if ($token[0] === T_NAMESPACE) {
            $declaringNamespace = true;

            continue;
        }

        if (! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        // `use Prefix\{A, B\C};` — the prefix is a name token followed by `\{`, and each member is a
        // name token of its own. Nothing else in PHP puts a brace there.
        if (($tokens[$i + 1][0] ?? null) === T_NS_SEPARATOR && ($tokens[$i + 2] ?? null) === '{') {
            $prefix = ltrim((string) $token[1], '\\').'\\';
            $alias = false;

            for ($i += 3; $i < $count && $tokens[$i] !== '}'; $i++) {
                $member = $tokens[$i];
                if (! is_array($member)) {
                    continue;
                }

                if ($member[0] === T_AS) {
                    $alias = true;
                } elseif (! in_array($member[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    continue;
                } elseif ($alias) {
                    $alias = false;
                } else {
                    $names[$prefix.$member[1]] = true;
                }
            }

            continue;
        }

        if ($declaringNamespace) {
            $declaringNamespace = false;

            continue;
        }

        // A bare `Foo` is a method, function or constant name far more often than a root-namespace
        // class, and a one-segment name is never the boundary violation these scans look for.
        if ($token[0] !== T_STRING) {
            $names[ltrim((string) $token[1], '\\')] = true;
        }
    }

    return array_keys($names);
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
 * Container-`scoped` services are forgotten first, because the build these harnesses compare against is
 * the one a SECOND `docuccino:generate` gets — a fresh process, where every per-build collector starts
 * empty. Leaving one populated from the previous build lets a warm build pass on state the cold build
 * next door put there, which is exactly the degradation the comparison exists to catch.
 *
 * @param  callable(Router): void  $routes
 * @param  callable(): TypeEngine|null  $engine  a FRESH engine per build (the harnesses count calls)
 */
function localityBuild(callable $routes, ?callable $engine = null, ?TypeEngine &$bound = null): GenerationResult
{
    app()->forgetScopedInstances();

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
            // The emitted bytes are a canonical PROJECTION of the document — component maps sorted, an
            // integer-valued float indistinguishable from the int — so equal bytes are not equal builds.
            // Overlays, transformers, lints and the differ all read the document in the shape below,
            // which is where a value restored in another type, or a bucket restored in another order,
            // actually shows.
            ->and($warm->document->toArray())->toBe($cold->document->toArray())
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

/**
 * Run one document lint over a raw document array and hand back what it reported. The completeness
 * lints share it rather than each re-rolling the draft → context wiring.
 *
 * @param  array<string, mixed>  $document
 * @return list<Diagnostic>
 */
function lintDiagnostics(DocumentTransformer $rule, array $document): array
{
    $collector = new DiagnosticCollector;
    $context = new DocumentContext(new DocumentConfig(key: 'd', info: ['title' => 'T', 'version' => '1']), 'doc:d', $collector);

    $rule->transform(new UirDocumentDraft($document), $context);

    return $collector->all();
}

/**
 * A minimal assembled document: operations keyed `METHOD /path`, webhooks keyed `METHOD name`, plus
 * the top-level tag declarations.
 *
 * @param  array<string, array<string, mixed>>  $operations
 * @param  list<array<string, mixed>>  $tags
 * @param  array<string, array<string, mixed>>  $webhooks
 * @return array<string, mixed>
 */
function lintDocument(array $operations, array $tags = [], array $webhooks = []): array
{
    $keyed = static function (array $nodes): array {
        $out = [];
        foreach ($nodes as $signature => $node) {
            [$method, $name] = explode(' ', $signature, 2);
            $out[$name][strtolower($method)] = $node;
        }

        return $out;
    };

    $document = ['info' => ['title' => 'T', 'version' => '1'], 'paths' => $keyed($operations)];
    if ($tags !== []) {
        $document['tags'] = $tags;
    }
    if ($webhooks !== []) {
        $document['webhooks'] = $keyed($webhooks);
    }

    return $document;
}

/**
 * Point the `default` document at the lint webhook fixtures, the way the shipped config documents it —
 * a directory relative to the application base path — by basing the app on the adapter package, so the
 * fixtures sit inside it exactly as `app/Webhooks` sits inside a real application.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>|null  $then  further config to mutate
 * @return callable(array<string, mixed>): array<string, mixed>
 */
function withLintWebhooks(?callable $then = null): callable
{
    app()->setBasePath(dirname(__DIR__).'/php/laravel');

    return static function (array $raw) use ($then): array {
        $raw['webhooks'] = ['dir' => 'tests/Fixtures/Webhooks/Lint'];

        return $then === null ? $raw : $then($raw);
    };
}

/**
 * Wire `docuccino:watch` for a test: a scripted {@see BuildRunner} standing in for the subprocess, and
 * a command instance the runner can stop after `$builds` builds — so no test depends on delivering a
 * real interrupt to the worker it runs in.
 *
 * `$onBuild` runs before that check, with the 1-based build number, for a row that has to move a
 * watched file between builds.
 *
 * @param  (callable(int): void)|null  $onBuild
 */
function scriptWatch(int $builds, ?callable $onBuild = null): ScriptedBuildRunner
{
    $command = new WatchCommand;
    app()->instance(WatchCommand::class, $command);

    $runner = new ScriptedBuildRunner(static function (int $call) use ($builds, $command, $onBuild): void {
        if ($onBuild !== null) {
            $onBuild($call);
        }

        if ($call >= $builds) {
            // The loop's own stop flag, which only a signal handler otherwise writes.
            (function (): void {
                $this->stopping = true;
            })->call($command);
        }
    });

    app()->instance(BuildRunner::class, $runner);

    return $runner;
}

/**
 * The contract-testing fixture: a small, provenance-carrying UIR document covering the shapes the
 * checker has to get right (a `$ref`'d component, a recursive one, a status range, a `default`, a
 * literal path beating a placeholder, a media type JSON Schema cannot check).
 *
 * @param  callable(array<string, mixed>): array<string, mixed>|null  $mutate
 */
function contractIndex(?callable $mutate = null): ContractIndex
{
    $document = loadFixture('contract.uir.json');

    return ContractIndex::fromArray($mutate === null ? $document : $mutate($document));
}

/**
 * One exchange, spelled the way a test reads best: everything optional but the three things that
 * decide which operation it is.
 *
 * @param  array<string, mixed>  $query
 * @param  array<string, string>  $headers
 */
function contractExchange(
    string $method,
    string $path,
    int $status = 200,
    string $responseBody = '',
    ?string $responseContentType = 'application/json',
    array $query = [],
    array $headers = [],
    string $requestBody = '',
    ?string $requestContentType = 'application/json',
): Exchange {
    return new Exchange(
        method: $method,
        path: $path,
        status: $status,
        query: $query,
        headers: $headers,
        requestBody: $requestBody,
        requestContentType: $requestContentType,
        responseBody: $responseBody,
        responseContentType: $responseContentType,
    );
}

/**
 * Check one exchange against the contract fixture, optionally mutating the document first.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>|null  $mutate
 */
function checkContract(Exchange $exchange, ?callable $mutate = null): CheckResult
{
    return (new ContractChecker(contractIndex($mutate)))->check($exchange);
}

/**
 * Emit the workbench `default` document as UIR into a temp file and point the contract assertions at
 * it. The one place the Laravel-side contract tests get a real artifact: a real build of the real
 * workbench through the real emitter, not a document written by hand to suit the assertion.
 */
function workbenchContract(?callable $mutateConfig = null): string
{
    bindStubEngine();

    $path = sys_get_temp_dir().'/docuccino-contract-'.getmypid().'.uir.json';
    file_put_contents($path, (new UirEmitter)->emit(generateDocument($mutateConfig)->document));

    ApiContract::using($path);

    return $path;
}

/**
 * A `TestResponse` for an exchange that never went through the router, so a contract test can pin the
 * exact request and response it is asserting about.
 *
 * @param  array<string, string>  $headers  response headers
 * @param  array<string, string>  $requestHeaders
 * @return TestResponse<Response>
 */
function contractResponse(
    string $method,
    string $uri,
    int $status = 200,
    string $body = '',
    array $headers = ['Content-Type' => 'application/json'],
    string $requestBody = '',
    array $requestHeaders = [],
): TestResponse {
    $server = [];
    foreach ($requestHeaders as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    if ($requestBody !== '') {
        $server['CONTENT_TYPE'] = $requestHeaders['Content-Type'] ?? 'application/json';
    }

    $request = Request::create($uri, $method, [], [], [], $server, $requestBody);

    return TestResponse::fromBaseResponse(new Response($body, $status, $headers), $request);
}

/**
 * A directory of this process's own, under the gitignored build tree, for a coverage-log fixture. Each
 * one carries the pid and eight random bytes, so two parallel workers running the same test never share
 * a fixture — and so the sweep below can only ever be taking away its own caller's.
 */
function coverageFixtureDir(string $slug): string
{
    $directory = dirname(__DIR__).'/build/tests/coverage-'.$slug.'-'.getmypid().'-'.bin2hex(random_bytes(8));
    mkdir($directory, 0755, true);

    return $directory;
}

/**
 * Remove a {@see coverageFixtureDir()} directory and everything under it.
 *
 * Two rules, and both of them are why this is here rather than written out per suite. It refuses any
 * path that is not under the build tree this process made, because a recursive delete pointed at the
 * wrong root is not a test failure but an incident. And it asks `is_link()` BEFORE `is_dir()`, because
 * `is_dir()` answers true for a link to a directory: a sweep that asked the other way round would
 * follow a planted link straight out of the fixture and start deleting whatever it pointed at.
 */
function removeCoverageFixture(string $directory): void
{
    $root = dirname(__DIR__).'/build/tests/';

    if (! str_starts_with($directory, $root) || ! is_dir($directory) || is_link($directory)) {
        return;
    }

    // A fixture that proved a directory cannot be read has to be removable afterwards, and the mode is
    // safe to put back here: nothing reaches this line that is not under the build tree this made.
    @chmod($directory, 0755);

    foreach ((array) @scandir($directory) as $entry) {
        if (! is_string($entry) || $entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (is_link($path) || ! is_dir($path)) {
            @unlink($path);

            continue;
        }

        removeCoverageFixture($path);
    }

    @rmdir($directory);
}

/**
 * Every ordering of a list, for a test that has to prove an answer does not depend on one.
 *
 * @param  list<string>  $items
 * @return list<list<string>>
 */
function permutationsOf(array $items): array
{
    if (count($items) <= 1) {
        return [$items];
    }

    $orders = [];
    foreach ($items as $index => $item) {
        $rest = $items;
        unset($rest[$index]);

        foreach (permutationsOf(array_values($rest)) as $order) {
            $orders[] = [$item, ...$order];
        }
    }

    return $orders;
}

/**
 * The lint rules core ships: everything under `Lint/` that is a document transformer. The neighbours in
 * there are options objects and pure helpers, so implementing the contract is what makes one a rule —
 * no list, and a helper that grows into a lint is caught the day it does.
 *
 * @return list<string>
 */
function shippedLints(): array
{
    $lints = [];

    foreach ((array) glob(dirname(__DIR__).'/php/core/src/Lint/*.php') as $file) {
        $class = 'Docuccino\Core\Lint\\'.basename((string) $file, '.php');

        if ((new ReflectionClass($class))->implementsInterface(DocumentTransformer::class)) {
            $lints[] = $class;
        }
    }

    sort($lints);

    return $lints;
}

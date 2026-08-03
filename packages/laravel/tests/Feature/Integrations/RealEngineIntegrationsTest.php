<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Integrations\ApiResources\JsonResourceSchema;
use Docuccino\Laravel\Integrations\FormRequest\ShapeToRuleSet;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateConfig;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateFacts;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateParameters;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldJsonApiResourceSchema;
use Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi\TimacdonaldArticleResource;

/**
 * Real-engine (out-of-process) coverage for the inference-dependent halves of the Phase-4 and Phase-5c
 * integrations, so the type-recovery those integrations lean on is exercised by the ACTUAL
 * PHPStan/Larastan engine — not only the deterministic stub. Complements the in-process unit tests
 * (which drive the mappers) and the existing JsonResponse status/payload real-engine smoke.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('recovers an API resource toArray shape as a constant array shape', function (): void {
    // The real engine analyses UserResource::toArray (@mixin User) into an array{id, name, email}.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Resources/UserResource.php',
        'App\\Http\\Resources\\UserResource',
        'toArray',
    ));

    $type = $analysis->returns[0]->type ?? null;
    expect($type)->toBeInstanceOf(ArrayShapeT::class);

    $keys = array_map(static fn ($field): string => (string) $field->key, $type->fields);
    expect($keys)->toBe(['id', 'name', 'email']);
})->group('fixture');

it('recovers real Eloquent model columns (incl. a cast-target type) via classMetadata', function (): void {
    // The real engine reflects App\Models\CatalogItem's typed public column properties — the same
    // classMetadata path ModelSchema consumes to build a model's object schema.
    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Models\\CatalogItem'));

    $byName = [];
    foreach ($metadata->properties as $property) {
        $byName[$property->name] = $property->type;
    }

    // The declared columns are all recovered (framework bookkeeping props may also be present).
    expect($byName)->toHaveKeys(['id', 'sku', 'is_active', 'description']);

    // Precise column types by reflection: id is an int, the boolean-cast column is a bool, and the
    // nullable column is a string|null union.
    expect($byName['id']->canonicalKey())->toBe(ScalarT::int()->canonicalKey())
        ->and($byName['is_active']->canonicalKey())->toBe(ScalarT::bool()->canonicalKey())
        ->and($byName['description'])->toBeInstanceOf(UnionT::class)
        ->and(array_filter($byName['description']->members, static fn ($m): bool => $m instanceof NullT))->not->toBeEmpty();
})->group('fixture');

it('recovers a real Data class shape via classMetadata (property types, not a stub)', function (): void {
    // The real engine reflects App\Data\ArticleData's typed public properties.
    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Data\\ArticleData'));

    $byName = [];
    foreach ($metadata->properties as $property) {
        $byName[$property->name] = $property->type;
    }

    expect(array_keys($byName))->toBe(['id', 'title', 'subtitle']);

    // Precise types recovered by reflection: id is an integer, subtitle is nullable.
    expect($byName['id']->canonicalKey())->toBe(ScalarT::int()->canonicalKey());

    $subtitle = $byName['subtitle'];
    expect($subtitle)->toBeInstanceOf(UnionT::class)
        ->and(array_filter($subtitle->members, static fn ($m): bool => $m instanceof NullT))->not->toBeEmpty();
})->group('fixture');

// ---------------------------------------------------------------------------------------------------
// Phase 5c integrations — the recovery half proven against the REAL engine (M2 / binding coverage).
// ---------------------------------------------------------------------------------------------------

it('recovers a real timacdonald JSON:API resource attributes shape and maps it to the JSON:API document', function (): void {
    // Real recovery: the engine reflects the timacdonald resource's toAttributes() into {title, body}.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Resources/ArticleJsonApiResource.php',
        'App\\Http\\Resources\\ArticleJsonApiResource',
        'toAttributes',
    ));

    $shape = $analysis->returns[0]->type ?? null;
    expect($shape)->toBeInstanceOf(ArrayShapeT::class)
        ->and(array_map(static fn ($field): string => (string) $field->key, $shape->fields))->toBe(['title', 'body']);

    // Drive the REAL-recovered attributes shape through the timacdonald mapper + shared JSON:API
    // document builder (real recovery → real mapper), proving they compose end-to-end. The mapper's
    // class guard reflects the resource FQCN, so the composition half runs against the loadable
    // test-fixture timacdonald resource seeded with the shape the real engine just recovered.
    $engine = new StubTypeEngine(analyses: [
        TimacdonaldArticleResource::class.'::toAttributes' => $analysis,
    ]);
    $components = new ComponentRegistry;
    $converter = new SchemaConverter(
        [new TimacdonaldJsonApiResourceSchema, new JsonResourceSchema, ...DefaultTypeMappers::all()],
        $engine,
        $components,
        new RepresentationPolicy,
    );
    $converter->toSchema(new ClassT(TimacdonaldArticleResource::class));

    $data = $components->schemas()['TimacdonaldArticleResource']['properties']['data'];
    expect($data['required'])->toBe(['id', 'type'])
        ->and($data['properties']['attributes']['properties'])->toHaveKeys(['title', 'body'])
        ->and($data['properties'])->not->toHaveKey('relationships');
})->group('fixture');

it('recovers spatie jsonPaginate() through the real engine and maps it to page[number]/page[size]', function (): void {
    // The REAL JsonApiPaginateTraceVisitor runs in the engine subprocess: it must recognise the
    // jsonPaginate() terminal one call deep, match the (where-narrowed) Eloquent builder receiver, and
    // fold the two literal overrides from the call site.
    $trace = FixtureRunner::traceJsonApiPaginate(
        'app/Http/Controllers/JsonApiPaginateController.php',
        'App\\Http\\Controllers\\JsonApiPaginateController',
        'index',
    );

    expect($trace['paginates'])->toBeTrue()
        ->and($trace['maxResults'])->toBe(100)
        ->and($trace['defaultSize'])->toBe(25);

    $facts = new JsonApiPaginateFacts;
    $facts->paginates = $trace['paginates'] === true;
    $facts->maxResultsOverride = is_int($trace['maxResults']) ? $trace['maxResults'] : null;
    $facts->defaultSizeOverride = is_int($trace['defaultSize']) ? $trace['defaultSize'] : null;

    $specs = (new JsonApiPaginateParameters)->build(new JsonApiPaginateConfig, $facts);
    $byName = [];
    foreach ($specs as $spec) {
        $byName[$spec->name] = $spec;
    }

    // The recovered terminal + overrides become the bracketed page params, with the folded literals
    // driving the size default (defaultSize) and ceiling (maxResults).
    expect(array_keys($byName))->toBe(['page[number]', 'page[size]'])
        ->and($byName['page[size]']->schema['default'])->toBe(25)
        ->and($byName['page[size]']->schema['maximum'])->toBe(100);
})->group('fixture');

it('recovers a real laravel-actions rules() array end-to-end into a RuleSet', function (): void {
    // Real recovery: the engine analyses the action's literal rules() array into a constant shape...
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Actions/PublishArticleAction.php',
        'App\\Actions\\PublishArticleAction',
        'rules',
    ));

    $shape = $analysis->returns[0]->type ?? null;
    expect($shape)->toBeInstanceOf(ArrayShapeT::class);

    // ...which ShapeToRuleSet (the integration's recovery tail) turns into a RuleSet.
    $ruleSet = (new ShapeToRuleSet)->convert($shape);
    expect(array_keys($ruleSet->fields))->toBe(['title', 'body']);

    $ruleNames = static fn (string $field): array => array_map(
        static fn ($rule): string => $rule->name,
        $ruleSet->fields[$field],
    );
    expect($ruleNames('title'))->toBe(['required', 'string', 'max'])
        ->and($ruleNames('body'))->toBe(['required', 'string']);
})->group('fixture');

<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\JsonApiResourceSchema;
use Docuccino\Laravel\Integrations\ApiResources\JsonResourceSchema;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\AuthorResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\CommentJsonApiResource;

/**
 * The API Resources integration (Phase 4): a JsonResource's toArray shape → hoisted component with
 * whenLoaded/when fields made optional and nested resources recursed, anonymous collections → arrays,
 * and Laravel 13 first-party JSON:API resources → JSON:API document schemas.
 */
function apiResourceEngine(): StubTypeEngine
{
    $loc = new SourceLocation('');
    $missing = new ClassT(ResourceReflector::MISSING_VALUE);
    $shape = static fn (array $fields): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT($fields), $loc)]);

    return new StubTypeEngine(analyses: [
        ArticleResource::class.'::toArray' => $shape([
            new ArrayShapeField('id', ScalarT::int()),
            new ArrayShapeField('title', ScalarT::string()),
            // whenLoaded → AuthorResource|MissingValue (optional, folds to the nested resource).
            new ArrayShapeField('author', UnionT::of([new ClassT(AuthorResource::class), $missing])),
            // when → string|MissingValue|null (optional AND nullable).
            new ArrayShapeField('excerpt', UnionT::of([ScalarT::string(), $missing, new NullT])),
        ]),
        AuthorResource::class.'::toArray' => $shape([
            new ArrayShapeField('name', ScalarT::string()),
            new ArrayShapeField('email', ScalarT::string()),
        ]),
        ArticleJsonApiResource::class.'::toAttributes' => $shape([
            new ArrayShapeField('title', ScalarT::string()),
            new ArrayShapeField('body', ScalarT::string()),
        ]),
        ArticleJsonApiResource::class.'::toRelationships' => $shape([
            new ArrayShapeField('author', ScalarT::string()),
        ]),
        ArticleJsonApiResource::class.'::toLinks' => $shape([
            new ArrayShapeField('self', ScalarT::string()),
        ]),
        // No toMeta analysis → the meta member is omitted.
        CommentJsonApiResource::class.'::toAttributes' => $shape([
            new ArrayShapeField('body', ScalarT::string()),
        ]),
        // The replies relationship types back to the comment resource itself — a self-reference the
        // component-hoist cycle-break must resolve to a $ref rather than recurse into.
        CommentJsonApiResource::class.'::toRelationships' => $shape([
            new ArrayShapeField('replies', new ClassT(CommentJsonApiResource::class)),
        ]),
    ]);
}

function resourceConverter(ComponentRegistry $components): SchemaConverter
{
    return new SchemaConverter(
        [new JsonApiResourceSchema, new JsonResourceSchema, ...DefaultTypeMappers::all()],
        apiResourceEngine(),
        $components,
    );
}

it('maps a JsonResource toArray shape to a component with optional whenLoaded fields', function (): void {
    $components = new ComponentRegistry;
    $ref = resourceConverter($components)->toSchema(new ClassT(ArticleResource::class))->schema;

    expect($ref['$ref'])->toBe('#/components/schemas/ArticleResource');

    $schemas = $components->schemas();
    expect($schemas)->toHaveKeys(['ArticleResource', 'AuthorResource']);

    $article = $schemas['ArticleResource'];
    expect(array_keys($article['properties']))->toBe(['id', 'title', 'author', 'excerpt'])
        // author (whenLoaded) and excerpt (when) are optional; id/title are required.
        ->and($article['required'])->toBe(['id', 'title'])
        // the whenLoaded value folds to the nested resource component.
        ->and($article['properties']['author'])->toBe(['$ref' => '#/components/schemas/AuthorResource'])
        // the when value strips MissingValue, leaving a nullable string.
        ->and($article['properties']['excerpt'])->toBe(['type' => ['string', 'null']]);
});

it('maps an anonymous resource collection to an array of its item', function (): void {
    $collection = new ClassT(ResourceReflector::ANONYMOUS_COLLECTION, [new ClassT(ArticleResource::class)]);
    $schema = resourceConverter(new ComponentRegistry)->toSchema($collection)->schema;

    expect($schema['type'])->toBe('array')
        ->and($schema['items'])->toBe(['$ref' => '#/components/schemas/ArticleResource']);
});

it('maps a first-party JSON:API resource to a JSON:API document schema', function (): void {
    $components = new ComponentRegistry;
    resourceConverter($components)->toSchema(new ClassT(ArticleJsonApiResource::class));

    $document = $components->schemas()['ArticleJsonApiResource'];
    expect($document['required'])->toBe(['data']);

    $data = $document['properties']['data'];
    expect($data['required'])->toBe(['id', 'type'])
        // id/type always present; attributes/relationships/links populated; meta omitted (no shape).
        ->and(array_keys($data['properties']))->toBe(['id', 'type', 'attributes', 'relationships', 'links'])
        ->and($data['properties']['attributes']['properties'])->toHaveKeys(['title', 'body'])
        ->and($data['properties']['id'])->toBe(['type' => 'string']);
});

it('cycle-breaks a self-referential JSON:API resource via a $ref to its own component', function (): void {
    $components = new ComponentRegistry;
    $ref = resourceConverter($components)->toSchema(new ClassT(CommentJsonApiResource::class))->schema;

    // The top-level conversion returns a $ref to the hoisted component (not an inlined document),
    // and terminates — an un-broken cycle would recurse until the stack overflows.
    expect($ref)->toBe(['$ref' => '#/components/schemas/CommentJsonApiResource']);

    $document = $components->schemas()['CommentJsonApiResource'];
    $relationships = $document['properties']['data']['properties']['relationships'];

    // The self-referential `replies` relationship folds to a $ref back at the same component.
    expect($relationships['properties']['replies'])->toBe(['$ref' => '#/components/schemas/CommentJsonApiResource']);
});

it('detects when a return type involves JSON:API', function (): void {
    expect(ResourceReflector::involvesJsonApi(new ClassT(ArticleJsonApiResource::class)))->toBeTrue()
        ->and(ResourceReflector::involvesJsonApi(new ClassT(ResourceReflector::JSON_API_COLLECTION, [new ClassT(ArticleJsonApiResource::class)])))->toBeTrue()
        ->and(ResourceReflector::involvesJsonApi(new ClassT(ArticleResource::class)))->toBeFalse();
});

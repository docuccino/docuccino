<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorData;

/**
 * The spatie/laravel-data integration (Phase 4): a Data class → hoisted component honouring the
 * reflected presentation facts (`#[Hidden]`, `#[MapName]`, `Optional`, `#[SchemaName]`/`#[SchemaId]`,
 * nested recursion), its collection variants → array / paginator envelopes, and the request-side
 * rule recovery that feeds the shared validation chain.
 */
function spatieDataEngine(): StubTypeEngine
{
    return new StubTypeEngine(classes: [
        ArticleData::class => new ClassMetadata(ArticleData::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('title', ScalarT::string()),
            new PropertyMetadata('body', ScalarT::string()),
            new PropertyMetadata('secret', ScalarT::string()),
            new PropertyMetadata('internal', ScalarT::int()),
            // The Optional marker leaks into the type; the mapper strips it and marks the prop optional.
            new PropertyMetadata('subtitle', UnionT::of([ScalarT::string(), new ClassT(DataClassReflector::OPTIONAL)])),
            new PropertyMetadata('author', UnionT::of([new ClassT(AuthorData::class), new NullT])),
        ]),
        AuthorData::class => new ClassMetadata(AuthorData::class, [
            new PropertyMetadata('name', ScalarT::string()),
            new PropertyMetadata('email', ScalarT::string()),
        ]),
    ]);
}

function convertData(ClassT $type): ComponentRegistry
{
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new DataSchema, ...DefaultTypeMappers::all()], spatieDataEngine(), $components);
    $converter->toSchema($type);

    return $components;
}

it('hoists a Data class to a component honouring #[SchemaName]/#[SchemaId], hidden, and mapping', function (): void {
    $components = convertData(new ClassT(ArticleData::class));

    $schemas = $components->schemas();
    // #[SchemaName('Article')] names the component; the nested Data hoists under its own name.
    expect($schemas)->toHaveKeys(['Article', 'AuthorData']);
    // #[SchemaId('article.v1')] pins the component identity.
    expect($components->schemaIds()['Article'] ?? null)->toBe('article.v1');

    $article = $schemas['Article'];
    // secret (spatie #[Hidden]) and internal (class-level Docuccino #[Hidden]) are dropped; title is
    // renamed to its #[MapName] output key.
    expect(array_keys($article['properties']))->toBe(['id', 'headline', 'body', 'subtitle', 'author']);
    // Optional (subtitle) and nullable (author) are non-required; the marker is stripped from subtitle.
    expect($article['required'])->toBe(['id', 'headline', 'body'])
        ->and($article['properties']['subtitle'])->toBe(['type' => 'string'])
        // author is nullable AuthorData → an anyOf referencing the hoisted component + null.
        ->and($article['properties']['author']['anyOf'][0]['$ref'] ?? null)->toBe('#/components/schemas/AuthorData');
});

it('renders a paginated DataCollection as the length-aware envelope', function (): void {
    $converter = new SchemaConverter([new DataSchema, ...DefaultTypeMappers::all()], spatieDataEngine(), new ComponentRegistry);

    $paginated = $converter->toSchema(new ClassT('Spatie\\LaravelData\\PaginatedDataCollection', [new ClassT(AuthorData::class)]))->schema;
    expect($paginated['type'])->toBe('object')
        ->and($paginated['required'])->toBe(['data'])
        ->and($paginated['properties']['data']['type'])->toBe('array')
        ->and($paginated['properties']['data']['items'])->toHaveKey('$ref')
        ->and($paginated['properties']['meta']['properties'])->toHaveKey('total');

    $simple = $converter->toSchema(new ClassT(DataClassReflector::DATA_COLLECTION, [new ClassT(AuthorData::class)]))->schema;
    expect($simple['type'])->toBe('array')
        ->and($simple['items'])->toHaveKey('$ref');
});

it('recovers request rules from Data properties + spatie validation attributes', function (): void {
    $engine = spatieDataEngine();
    $metadata = $engine->classMetadata(new ClassRef(ArticleData::class));
    $ruleSet = (new DataValidationRules)->build(ArticleData::class, $metadata, $engine);

    $names = static fn (string $field): array => array_map(
        static fn ($rule): string => $rule->name,
        $ruleSet->fields[$field] ?? [],
    );

    // #[MapName] input key ('heading') is used for the request; required + type synthesised.
    expect($ruleSet->fields)->toHaveKey('heading');
    expect($names('heading'))->toBe(['required', 'string']);
    // #[Max(500)] → 'max:500' fed through the shared chain, alongside synthesised presence/type.
    expect($names('body'))->toContain('required')->toContain('string')->toContain('max');
    $bodyMax = collect($ruleSet->fields['body'])->firstWhere('name', 'max');
    expect($bodyMax?->parameter(0))->toBe('500');
    // Optional marker → 'sometimes' instead of 'required'.
    expect($names('subtitle'))->toContain('sometimes')->not->toContain('required');
});

it('reflects presentation facts off the real Data class', function (): void {
    $reflector = new DataClassReflector;

    expect(DataClassReflector::isData(ArticleData::class))->toBeTrue()
        ->and($reflector->isPropertyHidden(ArticleData::class, 'secret'))->toBeTrue()
        ->and($reflector->isPropertyOptional(ArticleData::class, 'subtitle'))->toBeTrue()
        ->and($reflector->outputName(ArticleData::class, 'title'))->toBe('headline')
        ->and($reflector->inputName(ArticleData::class, 'title'))->toBe('heading')
        ->and($reflector->validationTokens(ArticleData::class, 'body'))->toBe(['max:500'])
        ->and($reflector->collectionKind('Spatie\\LaravelData\\PaginatedDataCollection'))->toBe('length');
});

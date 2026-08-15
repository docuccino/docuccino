<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataRequestExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ContainerShapeController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ContainerShapeData;

/**
 * The request side's answer to the rule vocabulary having one word — `array` — for every array shape.
 * A list synthesises the `key.*` item field Laravel writes by hand (the same trick the uploaded-file
 * list uses), a constant shape synthesises a `key.<member>` field per key, and a map, which Laravel has
 * no rule for at all, carries its value schema on an `additional_properties` rule.
 *
 * Mechanics only: the types are fed in as metadata. Their recovery from real source is proven against
 * the real engine in SpatieDataDegradedShapeTest.
 */

/**
 * One property's request schema, through the same normalise → order → convert sequence the extension
 * runs.
 *
 * @return array<string, mixed>
 */
function containerProperty(string $name, DType $type, bool $withConverter = true): array
{
    $metadata = new ClassMetadata(ContainerShapeData::class, [new PropertyMetadata($name, $type)]);
    $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);

    $ruleSet = (new DataValidationRules)->build(
        ContainerShapeData::class,
        $metadata,
        new NullTypeEngine,
        null,
        $withConverter ? $context : null,
    );
    $ordered = (new RuleOrdering)->order((new RuleSetNormalizer)->normalize($ruleSet));

    return (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))
        ->convert($ordered, $context)->schema['properties'][$name];
}

it('documents every recovered container shape', function (string $property, DType $type, array $expected): void {
    expect(containerProperty($property, $type))->toBe($expected);
})->with([
    // A map is a JSON OBJECT. `{"type": "array"}` is not merely vague here — a JSON object fails it.
    'array<string, mixed>' => ['settings', new MapT(ScalarT::string(), new UnknownT('mixed')), [
        'type' => 'object',
        'additionalProperties' => [],
    ]],
    'array<string, string>' => ['settings', new MapT(ScalarT::string(), ScalarT::string()), [
        'type' => 'object',
        'additionalProperties' => ['type' => 'string'],
    ]],
    'list<string>' => ['tags', new ListT(ScalarT::string()), [
        'type' => 'array',
        'items' => ['type' => 'string'],
    ]],
    'list<int>' => ['tags', new ListT(ScalarT::int()), [
        'type' => 'array',
        'items' => ['type' => 'integer'],
    ]],
    // Nested: the value schema comes off the same type→schema chain the response side uses, so both
    // sides describe `array<string, array<string, string|null>>` identically.
    'array<string, array<string, string|null>>' => ['theme', new MapT(ScalarT::string(), new MapT(ScalarT::string(), UnionT::of([ScalarT::string(), new NullT]))), [
        'type' => 'object',
        'additionalProperties' => [
            'type' => 'object',
            'additionalProperties' => ['type' => ['string', 'null']],
        ],
    ]],
    // A list of maps takes both paths at once: the item field is where the map's value schema lands.
    'list<array<string, int>>' => ['counters', new ListT(new MapT(ScalarT::string(), ScalarT::int())), [
        'type' => 'array',
        'items' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
    ]],
    'list<list<string>>' => ['tags', new ListT(new ListT(ScalarT::string())), [
        'type' => 'array',
        'items' => ['type' => 'array', 'items' => ['type' => 'string']],
    ]],
    'list<string|null>' => ['tags', new ListT(UnionT::of([ScalarT::string(), new NullT])), [
        'type' => 'array',
        'items' => ['type' => ['string', 'null']],
    ]],
    // A constant shape is an object with named members; an optional key stays out of `required`.
    'array{width: int, label?: string}' => ['box', new ArrayShapeT([
        new ArrayShapeField('width', ScalarT::int()),
        new ArrayShapeField('label', ScalarT::string(), optional: true),
    ]), [
        'type' => 'object',
        'properties' => [
            'width' => ['type' => 'integer'],
            'label' => ['type' => 'string'],
        ],
        'required' => ['width'],
    ]],
    // A shape whose member is itself a map keeps descending.
    'array{meta: array<string, string>}' => ['box', new ArrayShapeT([
        new ArrayShapeField('meta', new MapT(ScalarT::string(), ScalarT::string())),
    ]), [
        'type' => 'object',
        'properties' => ['meta' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
        'required' => ['meta'],
    ]],
    // Degradations. An element type nothing can say anything about contributes no child rather than an
    // `items: {}` that documents nothing…
    'list<mixed>' => ['tags', new ListT(new UnknownT('mixed')), ['type' => 'array']],
    // …and a positional shape, whose members differ per index, keeps the bare array rule.
    'array{0: int, 1: string}' => ['box', new ArrayShapeT([
        new ArrayShapeField(0, ScalarT::int()),
        new ArrayShapeField(1, ScalarT::string()),
    ], isList: true), ['type' => 'array']],
]);

it('carries the spatie markers and nullability the property states', function (): void {
    // `array<string, mixed>|Optional` — the Optional marker is stripped, and makes the field optional.
    $extras = containerProperty('extras', new MapT(ScalarT::string(), new UnknownT('mixed')));
    expect($extras)->toBe(['type' => 'object', 'additionalProperties' => []]);

    // A nullable map is an object OR null, never an array.
    $nullable = containerProperty('settings', UnionT::of([new MapT(ScalarT::string(), ScalarT::int()), new NullT]));
    expect($nullable)->toBe(['type' => ['object', 'null'], 'additionalProperties' => ['type' => 'integer']]);
});

it('degrades a map to the bare array rule when no converter is available', function (): void {
    // The value schema is the type→schema chain's answer, so without one the map falls back to the only
    // word the rule vocabulary has. Nothing else about the field changes.
    expect(containerProperty('settings', new MapT(ScalarT::string(), ScalarT::string()), withConverter: false))
        ->toBe(['type' => 'array']);
});

it('reaches the request body through the extension itself', function (): void {
    // The wiring half: the extension is what hands the rule builder the type→schema chain and normalises
    // the set it gets back, so the shapes above have to survive an actual handle() into a request body.
    $metadata = new ClassMetadata(ContainerShapeData::class, [
        new PropertyMetadata('settings', new MapT(ScalarT::string(), ScalarT::string())),
        new PropertyMetadata('tags', new ListT(ScalarT::string())),
    ]);
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/container-shapes'),
        actionRef: new ActionRef('', ContainerShapeController::class, 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(classes: [ContainerShapeData::class => $metadata]),
        document: new DocumentConfig('default', []),
        typeMappers: DefaultTypeMappers::all(),
        ruleTransformers: ValidationIntegration::transformers(),
    );

    (new DataRequestExtension)->handle($operation = new OperationDraft, $context);

    $body = $operation->freeze()->toArray()['requestBody'] ?? [];
    expect($body['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/ContainerShapeData']);

    $component = $context->components->schemas()['ContainerShapeData'];
    expect($component['properties']['settings'])->toBe(['type' => 'object', 'additionalProperties' => ['type' => 'string']])
        ->and($component['properties']['tags'])->toBe(['type' => 'array', 'items' => ['type' => 'string']]);
});

it('stops descending before a pathologically deep container runs away', function (): void {
    // Four levels of list is as far as the synthesised child paths go; the fifth is left as a bare array.
    $deep = new ListT(new ListT(new ListT(new ListT(new ListT(ScalarT::string())))));

    expect(containerProperty('tags', $deep))->toBe([
        'type' => 'array',
        'items' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'array']]]],
    ]);
});

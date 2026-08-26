<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataRequestExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\Support\DateWireFormat;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\DateLadderController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\DateLadderData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\DateOverrideData;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Optional;

/**
 * What a date-typed property publishes, in BOTH directions, from one class. The same value used to be
 * documented two contradictory ways: the request body took `format: date` from the `date` rule — one
 * word for everything non-relative `strtotime` parses, so narrower than the server accepts — while the
 * response took `date-time` from the app's `data.date_format`. A client round-tripping the value
 * truncated the time.
 *
 * Both sides are asserted from one class here, and the shape that reaches each is the *whole* leaf
 * schema, so neither direction can move without the other being seen to.
 */
function ladderMetadata(): ClassMetadata
{
    $carbon = new ClassT(CarbonImmutable::class);
    $nullableCarbon = new UnionT([$carbon, new NullT]);

    return new ClassMetadata(DateLadderData::class, [
        new PropertyMetadata('statedFormat', $carbon),
        new PropertyMetadata('castTimestamp', $carbon),
        new PropertyMetadata('castDateOnly', $carbon),
        new PropertyMetadata('declaredOnly', $carbon),
        new PropertyMetadata('nullableDeclared', $nullableCarbon),
        new PropertyMetadata('afterLiteral', $carbon),
        new PropertyMetadata('bareDateRule', ScalarT::string()),
        new PropertyMetadata('declaredWithDateRule', new UnionT([new ClassT(Optional::class), $carbon, new NullT])),
    ]);
}

/**
 * The REQUEST body's leaf schemas for a Data class, through the real recovery path.
 *
 * @param  array<string, ClassMetadata>  $classes
 * @return array<string, mixed>
 */
function dateWireRequest(string $fqcn, string $controller, array $classes, string $dateFormat = DateWireFormat::DEFAULT_FORMAT): array
{
    $components = new ComponentRegistry;
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/dates'),
        actionRef: new ActionRef('', $controller, 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(classes: $classes),
        document: new DocumentConfig('default', []),
        components: $components,
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
    );

    (new DataRequestExtension(new DataValidationRules(dateFormat: $dateFormat)))->handle(new OperationDraft, $context);

    /** @var array<string, mixed> $properties */
    $properties = $components->schemas()[class_basename($fqcn)]['properties'] ?? [];

    return $properties;
}

/**
 * The same class's RESPONSE component, for the side that always derived its format from the config.
 *
 * @param  array<string, ClassMetadata>  $classes
 * @return array<string, mixed>
 */
function dateWireResponse(string $fqcn, array $classes, string $dateFormat = DateWireFormat::DEFAULT_FORMAT): array
{
    $components = new ComponentRegistry;
    $converter = new SchemaConverter(
        [new DataSchema(dateFormat: $dateFormat), ...DefaultTypeMappers::all()],
        new StubTypeEngine(classes: $classes),
        $components,
    );
    $converter->toSchema(new ClassT($fqcn));

    /** @var array<string, mixed> $properties */
    $properties = $components->schemas()[class_basename($fqcn)]['properties'] ?? [];

    return $properties;
}

/**
 * One property's type/format pair on each side, which is all the two directions can contradict each
 * other about.
 *
 * @return array{request: array<string, mixed>, response: array<string, mixed>}
 */
function dateWireShapes(string $property, string $dateFormat = DateWireFormat::DEFAULT_FORMAT): array
{
    $classes = [DateLadderData::class => ladderMetadata()];
    $pick = static function (array $properties) use ($property): array {
        /** @var array<string, mixed> $schema */
        $schema = $properties[$property] ?? [];

        return ['type' => $schema['type'] ?? null, 'format' => $schema['format'] ?? null];
    };

    return [
        'request' => $pick(dateWireRequest(DateLadderData::class, DateLadderController::class, $classes, $dateFormat)),
        'response' => $pick(dateWireResponse(DateLadderData::class, $classes, $dateFormat)),
    ];
}

/**
 * The ladder, most specific source first. Each row pins BOTH directions, so a row where the app states
 * nothing per-property and the two columns differ is the defect coming back.
 */
it('resolves a date property\'s format from its most specific source, both ways', function (string $property, array $request, array $response): void {
    $shapes = dateWireShapes($property);

    expect($shapes['request'])->toBe($request)
        ->and($shapes['response'])->toBe($response);
})->with([
    // 1. `date_format:d/m/Y` — the app states the accepted wire format outright, and nothing displaces
    //    it. The response emits its own configured format, so the two honestly differ: this app parses
    //    `d/m/Y` in and writes ATOM out.
    'a date_format rule wins' => ['statedFormat',
        ['type' => 'string', 'format' => 'date'],
        ['type' => 'string', 'format' => 'date-time'],
    ],

    // 2. The `DateTimeInterfaceCast` format is what the cast really parses input with. `U` is the one
    //    shape that is not a string at all, and both sides say integer.
    'a U cast is a timestamp on both sides' => ['castTimestamp',
        ['type' => 'integer', 'format' => null],
        ['type' => 'integer', 'format' => null],
    ],
    'a date-only cast beats the configured format' => ['castDateOnly',
        ['type' => 'string', 'format' => 'date'],
        ['type' => 'string', 'format' => 'date-time'],
    ],

    // 3. The declared type with no rule stating otherwise — one config value, so one answer both ways.
    //    Before the fix the request published nothing at all here.
    'the declared type alone' => ['declaredOnly',
        ['type' => 'string', 'format' => 'date-time'],
        ['type' => 'string', 'format' => 'date-time'],
    ],
    'a nullable declared type keeps its null arm' => ['nullableDeclared',
        ['type' => ['string', 'null'], 'format' => 'date-time'],
        ['type' => ['string', 'null'], 'format' => 'date-time'],
    ],
    // The reported shape: an Optional marker stripped, a nullable union, and a `date` rule beside it.
    'the declared type beats a bare date rule' => ['declaredWithDateRule',
        ['type' => ['string', 'null'], 'format' => 'date-time'],
        ['type' => ['string', 'null'], 'format' => 'date-time'],
    ],

    // 4. A comparison bound is described, and the declared type still names the format.
    'a comparison target leaves the declared format standing' => ['afterLiteral',
        ['type' => 'string', 'format' => 'date-time'],
        ['type' => 'string', 'format' => 'date-time'],
    ],

    // 5. The control, pinned deliberately: a `date` rule with no date type behind it. Nothing better is
    //    known, so `date` remains the reading of intent — this row must not be "fixed".
    'a bare date rule with no date type still publishes date' => ['bareDateRule',
        ['type' => 'string', 'format' => 'date'],
        ['type' => 'string', 'format' => null],
    ],
]);

it('derives both directions from the one configured format', function (): void {
    // The guard against a second guess creeping back in: every property whose format has no
    // per-property source — no `date_format` rule, no cast — must read identically both ways, whatever
    // `data.date_format` says. Read off the fixture rather than listed, so a property added to it is
    // covered without a line here.
    $properties = [];
    foreach ((new ReflectionClass(DateLadderData::class))->getConstructor()->getParameters() as $parameter) {
        $stated = $parameter->getAttributes(DateFormat::class) !== [] || $parameter->getAttributes(WithCast::class) !== [];
        if (! $stated && dateWireCarriesDate($parameter->getType())) {
            $properties[] = $parameter->getName();
        }
    }

    // A scan that stopped seeing its shapes must fail rather than pass: the fixture's four
    // symmetric date properties are the population, and a property added to it lands here.
    expect($properties)->toHaveCount(4);

    foreach ($properties as $property) {
        foreach (['Y-m-d\TH:i:sP' => 'date-time', 'Y-m-d' => 'date'] as $configured => $expected) {
            $shapes = dateWireShapes($property, $configured);

            expect($shapes['request'])->toBe($shapes['response'])
                ->and($shapes['request']['format'])->toBe($expected);
        }
    }
});

/** Whether a promoted parameter's declared type is, or unions in, a `DateTimeInterface`. */
function dateWireCarriesDate(?ReflectionType $type): bool
{
    $members = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
    foreach ($members as $member) {
        if ($member instanceof ReflectionNamedType && ! $member->isBuiltin() && is_a($member->getName(), DateTimeInterface::class, true)) {
            return true;
        }
    }

    return false;
}

it('keeps the recovered wire format under a rules() override that says less', function (): void {
    // A `rules()` override replaces the inferred rules at its key, but the wire format is a fact about
    // the property's TYPE, not about its rules — so restating the bare `date` word, or naming no type at
    // all, has not restated it. Stating `date_format` has.
    $carbon = new ClassT(CarbonImmutable::class);
    $metadata = new ClassMetadata(DateOverrideData::class, [
        new PropertyMetadata('restatedDate', $carbon),
        new PropertyMetadata('statedFormat', $carbon),
        new PropertyMetadata('noTypeStated', $carbon),
    ]);
    $engine = new StubTypeEngine(classes: [DateOverrideData::class => $metadata]);
    $converter = new SchemaConverter(DefaultTypeMappers::all(), $engine, new ComponentRegistry);
    $override = new RuleSet([
        'restatedDate' => [ValidationRule::of('required'), ValidationRule::of('date')],
        'statedFormat' => [ValidationRule::of('required'), ValidationRule::of('date_format', ['d/m/Y'])],
        'noTypeStated' => [ValidationRule::of('required')],
    ]);

    $rules = (new DataValidationRules)->build(DateOverrideData::class, $metadata, $engine, $override, $converter);
    $schema = (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))
        ->convert((new RuleOrdering)->order((new RuleSetNormalizer)->normalize($rules)), $converter)->schema;

    expect($schema['properties']['restatedDate']['format'])->toBe('date-time')
        ->and($schema['properties']['statedFormat']['format'])->toBe('date')
        ->and($schema['properties']['statedFormat']['description'])->toBe('Expected format: d/m/Y')
        ->and($schema['properties']['noTypeStated']['format'])->toBe('date-time');
});

it('describes a Unix-timestamp property identically in both directions', function (): void {
    // The one date shape that is not a string: the integer says it, and the coarse rule's `format` goes
    // with the type it belonged to rather than lingering on an integer.
    $classes = [DateLadderData::class => ladderMetadata()];

    expect(dateWireRequest(DateLadderData::class, DateLadderController::class, $classes)['castTimestamp'])
        ->toBe(['type' => 'integer', 'description' => 'Unix timestamp (seconds).'])
        ->and(dateWireResponse(DateLadderData::class, $classes)['castTimestamp'])
        ->toBe(['type' => 'integer', 'description' => 'Unix timestamp (seconds).']);
});

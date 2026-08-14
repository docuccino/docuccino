<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MfaChallengeData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\SaveAnswersData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\SnapshotData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\UpdateNodeData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\UploadPolicyData;

/**
 * What the real engine's recovery — and its gaps — actually emit for a spatie Data class and for the two
 * framework response classes a route hands back when nothing names a payload. Every type and rule here
 * comes from the fixture app through the real engine; only the class the mapper reflects is a loadable
 * in-process twin, since the mapper's guards reflect the FQCN they are handed.
 *
 * Several expectations below pin behaviour that is WRONG on purpose, each marked DEGRADED with the gap
 * named. They pass today; closing a gap means updating its expectation deliberately, rather than
 * discovering it in a published document.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The real engine's metadata for a fixture-app class, re-keyed onto a loadable in-process twin. */
function realMetadataAs(string $fixtureFqcn, string $twinFqcn): ClassMetadata
{
    $real = ClassMetadata::fromArray(FixtureRunner::classMetadata($fixtureFqcn));

    return new ClassMetadata($twinFqcn, $real->properties, $real->summary);
}

/**
 * `[all hoisted components, the twin's own]` from the Data mapper, over the real engine's types.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function degradedDataComponent(string $fixtureFqcn, string $twinFqcn): array
{
    $engine = new StubTypeEngine(classes: [$twinFqcn => realMetadataAs($fixtureFqcn, $twinFqcn)]);
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new DataSchema, ...DefaultTypeMappers::all()], $engine, $components);
    $converter->toSchema(new ClassT($twinFqcn));

    $schemas = $components->schemas();

    return [$schemas, $schemas[substr((string) strrchr($twinFqcn, '\\'), 1)]];
}

/**
 * A rule set through the shared validation chain, as a JSON Schema object.
 *
 * @return array<string, mixed>
 */
function degradedRequestSchema(string $twinFqcn, ClassMetadata $metadata, ?RuleSet $override = null): array
{
    $ruleSet = (new DataValidationRules)->build($twinFqcn, $metadata, new NullTypeEngine, $override);
    $ordered = (new RuleOrdering)->order($ruleSet);
    $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);

    return (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))->convert($ordered, $context)->schema;
}

/** A traced `rules()` override from the fixture app, as the RuleSet the extension would pass on. */
function tracedOverride(string $relPath, string $fixtureFqcn): RuleSet
{
    $trace = FixtureRunner::traceRules($relPath, $fixtureFqcn, 'rules');

    return new RuleSet(array_map(
        static fn (array $rules): array => array_map(
            static fn (array $rule): ValidationRule => new ValidationRule($rule['name'], $rule['parameters'], $rule['note'] ?? null),
            $rules,
        ),
        $trace['fields'],
    ));
}

/**
 * `[the emitted 200 schema, the components it hoisted]` for a recovered return type. The framework
 * class's own metadata comes from the real engine — reflecting that class is what produces the members.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function degradedResponseSchema(ClassT $returnType, string $frameworkFqcn): array
{
    $engine = new StubTypeEngine(
        analyses: [
            'Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(
                returns: [new ReturnSite($returnType, new SourceLocation(''))],
            ),
        ],
        classes: [$frameworkFqcn => ClassMetadata::fromArray(FixtureRunner::classMetadata($frameworkFqcn))],
    );
    app()->instance(TypeEngine::class, $engine);

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');
    $document = app(DocumentGenerator::class)->generate($config, $engine)->document->toArray();

    return [
        $document['paths']['/api/forms']['get']['responses']['200']['content']['application/json']['schema'] ?? [],
        $document['components']['schemas'] ?? [],
    ];
}

it('emits a constructor-@param map as an object with additionalProperties', function (): void {
    // The control the fixes must preserve: `context` is the only array member of the fixture app's
    // SnapshotData whose generic is written in the constructor `@param` block.
    [, $component] = degradedDataComponent('App\\Data\\SnapshotData', SnapshotData::class);

    expect($component['properties']['context'])->toBe([
        'type' => 'object',
        'additionalProperties' => [],
        'description' => 'Inline request context as it stood at submit.',
    ]);
})->group('fixture');

it('DEGRADED: emits prose and no shape for every array member typed in its own @var', function (string $property, array $expected): void {
    // KNOWN GAP (the promoted-property `@var` reader). The recovered type is UnknownT, and an unknown
    // type has no schema at all — not `{"type": "array"}`, not `{"type": "object"}`, nothing. The prose
    // comes off the SAME docComment the type was never taken from, which is why the symptom reads as a
    // description with nothing beside it rather than as a missing property.
    [, $component] = degradedDataComponent('App\\Data\\SnapshotData', SnapshotData::class);

    expect($component['properties'][$property])->toBe($expected);
})->with([
    '@var array<string, mixed>' => ['candidate', [
        'description' => "Inline candidate profile state as it stood at submit: identity, contact details and whatever\nelse the tenant's profile schema carried.",
    ]],
    '@var array<string, array<string, string|null>>' => ['theme_data', [
        'description' => 'Theme colour and typography values, keyed by mode then by token.',
    ]],
    '@var list<SnapshotFormData>' => ['forms', [
        'description' => "One entry per form zone in the pinned blueprint version's candidate-application tab.",
    ]],
    '@var array<int, string>' => ['permissions', [
        'description' => 'Flat list of permission strings the candidate held at submit.',
        'example' => '["listing.view", "listing.create"]',
    ]],
    '@phpstan-var list<SnapshotFormData>' => ['attachments', [
        'description' => "Attachments carried alongside the snapshot, documented with the analyser-prefixed tag some\nteams standardise on.",
    ]],
])->group('fixture');

it('DEGRADED: leaves the item class of a dropped list out of components entirely', function (): void {
    // KNOWN GAP, and the knock-on that makes it expensive: `forms` is a `list<SnapshotFormData>`, so
    // losing its type also loses the only reference to that Data class. Nothing hoists it, and a reader
    // of the document has no way to learn the shape exists.
    [$schemas] = degradedDataComponent('App\\Data\\SnapshotData', SnapshotData::class);

    expect(array_keys($schemas))->toBe(['SnapshotData']);
})->group('fixture');

it('DEGRADED: emits an untyped item array for a DataCollection whose generic was never read', function (): void {
    // KNOWN GAP (the bare-ClassT-is-precise rule). This generic IS in the constructor `@param`, so a fix
    // to the `@var` reader leaves it exactly as it is — the two gaps are independent.
    [$schemas, $component] = degradedDataComponent('App\\Data\\MfaChallengeData', MfaChallengeData::class);

    expect($component['properties']['mfa_factors'])->toBe([
        'type' => 'array',
        'items' => [],
        'description' => 'The factors the user can complete the challenge with.',
    ])
        ->and(array_keys($schemas))->toBe(['MfaChallengeData']);
})->group('fixture');

it('DEGRADED: collapses a recovered map and list to a bare array on the request side', function (): void {
    // KNOWN GAP, and the highest-volume one: the types ARE recovered here (PromotedPropertyDocblockTest
    // pins that), and the request path then routes them through validation rules, whose vocabulary has
    // one word for every array shape. `answers` loses its keys, `touched_fields` loses its items — the
    // client is told "an array" and nothing more.
    $metadata = realMetadataAs('App\\Data\\SaveAnswersData', SaveAnswersData::class);
    $schema = degradedRequestSchema(SaveAnswersData::class, $metadata);

    expect($schema['properties']['answers'])->toBe(['type' => ['array', 'null']])
        ->and($schema['properties']['touched_fields'])->toBe(['type' => 'array'])
        // The scalar beside them is documented in full, so the loss is the array vocabulary, not the path.
        ->and($schema['properties']['zone_key'])->toBe(['type' => 'string'])
        // And a defaulted property is still demanded — `touched_fields = []` may legitimately be omitted.
        ->and($schema['required'])->toBe(['zone_key', 'touched_fields']);
})->group('fixture');

it('DEGRADED: emits a keywordless property for a rules() override it could not fold', function (): void {
    // KNOWN GAP, three of them stacked. `Rule::in(MediaCollections::validNames())` folds to an `in` rule
    // with EMPTY parameters rather than to nothing; the override then OVERWRITES the property inference
    // that would have said `string`; and the choice transformer returns early on an empty value set. The
    // property survives with zero keywords — strictly worse than never having written the override.
    $metadata = realMetadataAs('App\\Data\\UploadPolicyData', UploadPolicyData::class);
    $override = tracedOverride('app/Data/UploadPolicyData.php', 'App\\Data\\UploadPolicyData');

    expect($override->fields['collection'][0]->name)->toBe('in')
        ->and($override->fields['collection'][0]->parameters)->toBe([]);

    // What property inference alone would have documented, for contrast…
    expect(degradedRequestSchema(UploadPolicyData::class, $metadata)['properties']['collection'])
        ->toBe(['type' => 'string']);

    // …and what the override replaces it with.
    expect(degradedRequestSchema(UploadPolicyData::class, $metadata, $override)['properties']['collection'])->toBe([]);
})->group('fixture');

it('DEGRADED: raises no diagnostic for the override it silently dropped', function (): void {
    // KNOWN GAP, and the reason the one above ships unnoticed: `collection` IS among the traced fields,
    // so it is never reported unrecoverable, and the shared rules analysis suppresses its
    // `validation.rule-unrecoverable` on exactly that test. The failure is completely silent.
    $trace = FixtureRunner::traceRules('app/Data/UploadPolicyData.php', 'App\\Data\\UploadPolicyData', 'rules');

    expect($trace['unrecoverable'])->toBe([])
        ->and(array_keys($trace['fields']))->toBe(['collection']);
})->group('fixture');

it('DEGRADED: documents a request property for a field the API prohibits', function (): void {
    // KNOWN GAP. `label` has no property at all — the override names it only to reject it. `prohibited`
    // is deliberately a no-op in the rule vocabulary, so the field lands in the request body as an
    // optional, shapeless property: the documentation invites exactly what the API refuses.
    $metadata = realMetadataAs('App\\Data\\UpdateNodeData', UpdateNodeData::class);
    $override = tracedOverride('app/Data/UpdateNodeData.php', 'App\\Data\\UpdateNodeData');
    $schema = degradedRequestSchema(UpdateNodeData::class, $metadata, $override);

    expect(array_keys($schema['properties']))->toBe(['name', 'metadata', 'label'])
        ->and($schema['properties']['label'])->toBe([])
        ->and($schema)->not->toHaveKey('required');
})->group('fixture');

it('DEGRADED: emits an array type alongside object properties for a dotted rule key', function (): void {
    // KNOWN GAP, and the same root as the array-vocabulary collapse: `metadata` gets `{"type": "array"}`
    // from its own rule and `properties` from its dotted children, and the assembler only defaults a
    // missing type rather than reconciling the one already there. The result is not a coherent schema —
    // no `array` has `properties` — and a validator handed this rejects sound documents.
    $metadata = realMetadataAs('App\\Data\\UpdateNodeData', UpdateNodeData::class);
    $override = tracedOverride('app/Data/UpdateNodeData.php', 'App\\Data\\UpdateNodeData');
    $schema = degradedRequestSchema(UpdateNodeData::class, $metadata, $override);

    expect($schema['properties']['metadata'])->toBe([
        'type' => 'array',
        'properties' => [
            'retention' => [
                'type' => 'object',
                'properties' => [
                    'mode' => [
                        'type' => 'string',
                        'description' => 'Required when any of metadata is present.',
                    ],
                ],
            ],
        ],
    ]);
})->group('fixture');

it('DEGRADED: emits an empty 200 body for a bare JsonResponse', function (): void {
    // KNOWN GAP. Nothing at the call site names a payload, so the recovered type is a bare
    // `Illuminate\Http\JsonResponse`. The pipeline unwraps it and finds no payload generic, and the 200
    // ends up with a content entry whose schema is empty — a documented JSON body that says nothing.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/SsoRedirectController.php',
        'App\\Http\\Controllers\\SsoRedirectController',
        'reset',
    ));
    $returnType = $analysis->returns[0]->type ?? null;
    expect($returnType)->toBeInstanceOf(ClassT::class)
        ->and($returnType->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and($returnType->typeArgs)->toBe([]);

    [$schema, $components] = degradedResponseSchema($returnType, 'Illuminate\\Http\\JsonResponse');

    expect($schema)->toBe([])
        ->and($components)->toBe([]);
})->group('fixture');

it('DEGRADED: gives a 302 a JSON body of RedirectResponse internals', function (): void {
    // KNOWN GAP, and worse than the JsonResponse one above: `RedirectResponse` gets no unwrapping, so it
    // is documented the only way a bare class can be — by reflecting it. The response object's own
    // `original`/`exception`/`headers` members are hoisted as a component and referenced as the body,
    // two of them REQUIRED. A 302 carries no JSON at all, so every keyword here is fiction.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/SsoRedirectController.php',
        'App\\Http\\Controllers\\SsoRedirectController',
        'connection',
    ));
    $returnType = $analysis->returns[0]->type ?? null;
    expect($returnType)->toBeInstanceOf(ClassT::class)
        ->and($returnType->fqcn)->toBe('Illuminate\\Http\\RedirectResponse');

    [$schema, $components] = degradedResponseSchema($returnType, 'Illuminate\\Http\\RedirectResponse');

    expect($schema['$ref'] ?? null)->toBe('#/components/schemas/RedirectResponse');

    $component = $components['RedirectResponse'];
    unset($component['x-docuccino']);
    expect($component)->toBe([
        'type' => 'object',
        'properties' => [
            'original' => ['description' => 'The original content of the response.'],
            'exception' => [
                'type' => ['object', 'null'],
                'description' => 'The exception that triggered the error response (if applicable).',
            ],
            'headers' => ['type' => 'object'],
        ],
        'required' => ['original', 'headers'],
    ]);
})->group('fixture');

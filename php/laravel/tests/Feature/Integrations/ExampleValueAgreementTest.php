<?php

declare(strict_types=1);

use Docuccino\Core\Emit\SchemaExampleFactory;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerResponseBuilder;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ExportFailure;

/**
 * "One representative value for this schema" is computed at more than one site — core's collection
 * exporter builds a request body a consumer can send, and the adapter fills the member of an error example
 * that no render arm could read — and covering each says nothing about whether they answer alike. A schema
 * carrying both a `const` and an `example` was answered two different ways, and one of the two was a value
 * the schema itself rejects.
 *
 * So the rows below state the answer INDEPENDENTLY, from the rule rather than from either implementation,
 * and put it to both. The rule, in the order the keywords are read:
 *
 *   - a `const` leaves exactly ONE legal value, so it outranks everything, an author's own `example`
 *     included: anything else stated beside it is a value that same schema rejects;
 *   - failing that, a value the schema STATES is what the author said the member looks like;
 *   - failing that, a value domain the schema NAMES answers for itself — an `enum` by its first entry,
 *     since a list's order is authored, and a `format` by the one sample the whole build uses;
 *   - and only then the declared type, whose empty object is `{}` and never the JSON list `[]`.
 *
 * A row naming a DTYPE is asked through the document the adapter publishes, which is where agreement is
 * owed: the member's schema is read back out of the finished component and handed to core's factory, so
 * both sites answer the same bytes. A row naming a SCHEMA is one this context's converter cannot mint —
 * `format` reaches a real document through the date-wire integration, and the export family's golden pins
 * it there — so it is put to the two ladders directly. Comparison is by ENCODED JSON, because that is what
 * a consumer copies, and because `[]` and `{}` are one PHP value, which is the axis one of these rows is
 * about.
 *
 * The second test is the other half: the differences that deliberately STAND, each with the reason it
 * costs nothing. They are asserted rather than described, so a change closing one — or widening it —
 * fails here and gets decided rather than discovered.
 */
function agreementContext(DType $probe, ?string $documented = null): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/probe'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(classes: [
            'App\\Data\\ProbeProblem' => new ClassMetadata('App\\Data\\ProbeProblem', [
                new PropertyMetadata('status', ScalarT::int()),
                new PropertyMetadata('probe', $probe, example: $documented),
            ]),
        ]),
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
    );
}

/**
 * One error body with one member the render arm could not read, published through the real tier: the
 * member's schema as the document states it, the components it may point into, and the value the fill put
 * in the example.
 *
 * @return array{array<string, mixed>, array<string, mixed>, mixed}
 */
function agreementProbe(DType $probe, ?string $documented = null): array
{
    $context = agreementContext($probe, $documented);

    $draft = HandlerResponseBuilder::build(
        new ActionAnalysis(returns: [new ReturnSite(
            new ClassT('Illuminate\\Http\\JsonResponse', [
                new ClassT('App\\Data\\ProbeProblem'),
                new LiteralT(409),
                new LiteralT('application/problem+json'),
                new ArrayShapeT([
                    new ArrayShapeField('status', new LiteralT(409)),
                    new ArrayShapeField('probe', new UnknownT('constructor argument not folded')),
                ]),
            ]),
            new SourceLocation(''),
        )]),
        $context,
        Contribution::integration('inferred-handler'),
        new ThrownException('App\\Exceptions\\ProbeFailure', 409, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
        'App\\Exceptions\\Handler::render',
    );

    $frozen = $draft?->freeze()->toArray() ?? [];
    $schemas = $context->components->schemas();

    /** @var array<string, mixed> $spec */
    $spec = $schemas['ProbeProblem']['properties']['probe'] ?? [];

    return [$spec, ['schemas' => $schemas], $frozen['content']['application/problem+json']['example']['probe'] ?? null];
}

it('illustrates a member exactly as core illustrates the same published schema', function (DType|array $probe, ?string $documented, string $expected): void {
    [$spec, $components, $filled] = $probe instanceof DType
        ? agreementProbe($probe, $documented)
        : [$probe, [], agreementLadder($probe)];

    // Anti-vacuity: the schema really did come out carrying something to read, so an agreement on
    // `"string"` between two sites both looking at `{}` cannot pass for one.
    expect($spec)->not->toBe([]);

    expect(json_encode($filled))->toBe($expected)
        ->and(json_encode((new SchemaExampleFactory)->value($spec, $components)))->toBe($expected);
})->with([
    // The contradiction that started this. A `const` admits one value; the `example` beside it is a value
    // the schema rejects, so no producer of a representative value may publish it.
    'a const beside an authored example' => [new LiteralT('quota-exceeded'), 'anything-else', '"quota-exceeded"'],
    // A closed set answers for itself, by its FIRST entry — a list's order is authored, and every other
    // reader of the document shows that same branch.
    'an enum' => [new EnumT(ExportFailure::class, ['QuotaExceeded', 'SourceUnavailable']), null, '"QuotaExceeded"'],
    // An entry of `null` is a value the enum ADMITS, so it is the illustration — absence and a null value
    // are not the same reading, and treating them alike falls through to a type whose `"string"` those
    // same two lines of schema reject.
    'an enum whose first entry is null' => [['type' => ['string', 'null'], 'enum' => [null, 'b']], null, 'null'],
    // One sample per format, for the whole build: a second table is how one producer starts publishing a
    // different instant for the same keyword.
    'a format' => [['type' => 'string', 'format' => 'date-time'], null, '"2024-01-01T00:00:00Z"'],
    // `{}`, never `[]`. A PHP array cannot spell the empty JSON object, and the list it writes back as is
    // a value the `type: object` beside it rejects (design §1, the empty-object invariant).
    'an object with nothing required' => [new MapT(ScalarT::string(), new UnknownT('mixed')), null, '{}'],
    // The bare types, where the whole answer is the type's own most obvious inhabitant.
    'a boolean' => [ScalarT::bool(), null, 'true'],
    'an integer' => [ScalarT::int(), null, '0'],
    'a string' => [ScalarT::string(), null, '"string"'],
]);

/**
 * The adapter's ladder, asked directly. Every row below is a schema the response converter cannot mint on
 * a body member, so there is no document to read one out of — which is itself most of the reason each
 * difference is allowed to stand.
 *
 * @param  array<string, mixed>  $spec
 */
function agreementLadder(array $spec): mixed
{
    $method = new ReflectionMethod(HandlerResponseBuilder::class, 'typePlaceholder');

    /** @var array{mixed, bool} $answer */
    $answer = $method->invoke(null, $spec, agreementContext(ScalarT::string()), 0);

    return $answer[0];
}

it('keeps the divergences it means to keep, and no others', function (array $spec, string $core, string $adapter): void {
    expect(json_encode((new SchemaExampleFactory)->value($spec)))->toBe($core)
        ->and(json_encode(agreementLadder($spec)))->toBe($adapter);
})->with([
    // The one difference that is about PURPOSE rather than reach, and the reason full delegation was
    // rejected. An exported request body is a form someone is about to edit, so hiding the optional half
    // hides the contract; an error example shows the members THIS arm really carries, and one the render
    // path did not supply and the schema does not require is a member this response may not have at all.
    'an optional member of an object' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
        '{"a":"string"}',
        '{}',
    ],
    // `not` is minted by a `not_in:` rule, which writes a REQUEST body. Core refuses a value it cannot
    // prove escapes the constraint; the adapter's fill has no "no value" to answer — omitting the member
    // would drop the whole example — so it answers from the type and the example lint is the backstop.
    'a value domain stated negatively' => [
        ['type' => 'string', 'not' => ['const' => 'string']],
        'null',
        '"string"',
    ],
    // Composition: core merges every branch, the adapter reads none. Reachable only through an
    // intersection-typed body member, which nothing in the corpus states on an error body.
    'a composed schema' => [
        ['allOf' => [['type' => 'integer']]],
        '0',
        '"string"',
    ],
    // A map of named examples is a Media Type Object's way of illustrating, and nothing this package
    // writes puts one on a body member — so the adapter reads the singular `example` alone.
    'a map of named examples' => [
        ['type' => 'string', 'examples' => ['b' => ['value' => 'B'], 'a' => ['value' => 'A']]],
        '"A"',
        '"string"',
    ],
    // A bound CONSTRAINS a value without naming one. Core moves onto the nearest legal number; the
    // adapter deliberately reads no constraint keyword — no constant satisfies an arbitrary `pattern`,
    // and the bounds arrive on request bodies rather than on the response members this fills.
    'a bounded number' => [
        ['type' => 'integer', 'minimum' => 18],
        '18',
        '0',
    ],
]);

it('flattens a deep nesting sooner than core does, and both stop', function (): void {
    // Both caps exist for the same reason — a self-referential schema never ends — and neither number is a
    // fact about a document, so they are free to differ. What must hold is that each stops: the row is here
    // to fail if one of them ever stops stopping.
    $spec = ['type' => 'string'];
    for ($depth = 0; $depth < 10; $depth++) {
        $spec = ['type' => 'object', 'properties' => ['down' => $spec], 'required' => ['down']];
    }

    expect(json_encode(agreementLadder($spec)))->toBe('{"down":{"down":{"down":{}}}}')
        ->and(json_encode((new SchemaExampleFactory)->value($spec)))
        ->toBe('{"down":{"down":{"down":{"down":{"down":{"down":{"down":{"down":{"down":{}}}}}}}}}}');
});

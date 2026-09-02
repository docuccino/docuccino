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
use Docuccino\Core\Inference\DType\IntersectionT;
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
 *   - failing that, a numeric BOUND, which is the one kind of constraint that also names a value:
 *     `minimum: 5` is satisfied by 5, a ceiling below the neutral value is satisfied by itself, and an
 *     exclusive bound by the nearest value it ADMITS — exactly `floor(x) + 1` on an `integer`, and
 *     `x + 1` on a `number`, whose tightest answer is an epsilon no deterministic table can name. A
 *     `multipleOf` is applied last, because a step is the one keyword that can move a value back out of
 *     the range the bounds put it in;
 *   - and only then the declared type, whose empty object is `{}` and never the JSON list `[]`.
 *
 * A conjunction is reduced before any of that is read: an `allOf` is the one schema its branches add up
 * to. And the ladder stops at a `pattern`, which is where the difference between the two kinds of
 * constraint shows: a bound names a legal value and a regex names none, so both sites answer a patterned
 * member from its type and the build's own example lint is the backstop.
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
            // The two halves an intersection-typed member is the conjunction of. Registered always and
            // converted only where a row names them, so no other row's document changes shape.
            'App\\Data\\ProbeAudit' => new ClassMetadata('App\\Data\\ProbeAudit', [
                new PropertyMetadata('actor', ScalarT::string()),
            ]),
            'App\\Data\\ProbeAttempt' => new ClassMetadata('App\\Data\\ProbeAttempt', [
                new PropertyMetadata('attempt', ScalarT::int()),
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
    // A conjunction, through the document: an intersection-typed member is `allOf` of its converted
    // halves, and a member whose schema says it satisfies two shapes at once cannot be illustrated
    // `"string"` — every branch of it rejects that. The conjunction of two objects is the object with
    // both their members, so the illustration is one instance of both halves.
    'an intersection of two shapes' => [
        new IntersectionT([new ClassT('App\\Data\\ProbeAudit'), new ClassT('App\\Data\\ProbeAttempt')]),
        null,
        '{"actor":"string","attempt":0}',
    ],
    // A floor NAMES a legal value, which is what separates it from the `pattern` two rows down: 18
    // satisfies `minimum: 18` and `0` is a value that same schema rejects.
    'a floor' => [['type' => 'integer', 'minimum' => 18], null, '18'],
    // The negative half, and the one that says the fill is not simply "publish the floor": a bound the
    // neutral value already satisfies leaves it exactly where it was.
    'a floor the neutral value already clears' => [['type' => 'integer', 'minimum' => -3], null, '0'],
    // A ceiling below the neutral value is satisfied by ITSELF, so the illustration moves down to it —
    // the same rule read in the other direction, and `0` would be over the cap.
    'a ceiling below the neutral value' => [['type' => 'integer', 'maximum' => -5], null, '-5'],
    // An EXCLUSIVE floor is where the two types part. `0` is not greater than 0, and the nearest integer
    // that is, is 1.
    'an exclusive floor on an integer' => [['type' => 'integer', 'exclusiveMinimum' => 0], null, '1'],
    // The same bound on a `number`, where the tightest legal answer is 0 plus an epsilon: no
    // deterministic table can name one, so the answer is the next whole step up — legal, and the same
    // bytes on every machine, which is what the illustration owes.
    'an exclusive floor on a number' => [['type' => 'number', 'exclusiveMinimum' => 0], null, '1'],
    'an exclusive ceiling' => [['type' => 'integer', 'exclusiveMaximum' => 0], null, '-1'],
    // A step is applied LAST, because it is the one keyword that can move a value back out of the range:
    // the floor puts the answer at 1, and 1 is not a multiple of 5.
    'a step above a floor' => [['type' => 'integer', 'minimum' => 1, 'multipleOf' => 5], null, '5'],
    // The draft-04 spelling, where `exclusiveMinimum` is a BOOLEAN modifying `minimum`. It names no
    // value, so it is read as stating nothing and `minimum: 0` answers alone — the UIR is written in
    // 2020-12, and reading the older dialect's flag as a bound would put the illustration at 1 under a
    // schema that admits 0.
    'a draft-04 exclusive flag' => [['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => true], null, '0'],
    // Where the ladder stops. A `pattern` constrains a value and names none — no constant satisfies an
    // arbitrary regex — so both sites answer from the type and the build's example lint is the backstop.
    // This row is the pair of the floor rows above: it is why "read the constraint keywords" is not the
    // rule, and "read the ones that name a value" is.
    'a pattern' => [['type' => 'string', 'pattern' => '^[A-Z]{3}$'], null, '"string"'],
    // A conjunction of ONE, the smallest shape that has to be reduced before the type under it is
    // readable at all.
    'a conjunction of one branch' => [['allOf' => [['type' => 'integer']]], null, '0'],
    // Contradictory branches: nothing satisfies both, so neither site can publish a true value. Both
    // show the FIRST branch — the branch every other reader of the document shows — and the example lint
    // names the contradiction, which is a diagnostic the document's author can act on.
    'a conjunction whose branches disagree on the type' => [
        ['allOf' => [['type' => 'integer'], ['type' => 'string']]],
        null,
        '0',
    ],
    // A boolean subschema is a schema at every subschema position and `true` is the empty one, so the
    // branch states nothing and the conjunction is what the other branch says.
    'a conjunction with an empty branch' => [['allOf' => [true, ['type' => 'string']]], null, '"string"'],
    // The document shape a marker interface produces: a branch describing nothing but object-ness. It is
    // still a branch the value has to satisfy, and it contributes no member to the illustration.
    'a conjunction with a branch that only says object' => [
        ['allOf' => [
            ['type' => 'object', 'properties' => ['actor' => ['type' => 'string']], 'required' => ['actor']],
            ['type' => 'object'],
        ]],
        null,
        '{"actor":"string"}',
    ],
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
    // The last four rows are that same asymmetry reached through other keywords.
    'a value domain stated negatively' => [
        ['type' => 'string', 'not' => ['const' => 'string']],
        'null',
        '"string"',
    ],
    // A map of named examples is a Media Type Object's way of illustrating, and nothing this package
    // writes puts one on a body member — so the adapter reads the singular `example` alone.
    'a map of named examples' => [
        ['type' => 'string', 'examples' => ['b' => ['value' => 'B'], 'a' => ['value' => 'A']]],
        '"A"',
        '"string"',
    ],
    // The three rows below are ONE difference, reached three ways: core can answer NO VALUE and the fill
    // cannot. Core's caller lists a schema's members as fields of its own, so a member nothing satisfies
    // is simply left out; the fill's example is the only illustration its response has, and dropping the
    // member would drop the whole example. So it keeps the type's own placeholder and the build's
    // example lint names what the schema says — which, at every one of these, is a contradiction its
    // author can act on. Each is its own row because a dataset only proves what it lists.
    //
    // Bounds that cross: no number is both at least 5 and at most 3.
    'bounds that cross' => [
        ['type' => 'integer', 'minimum' => 5, 'maximum' => 3],
        'null',
        '0',
    ],
    // A step with no room between the bounds — the same fact one keyword further on, and the reason the
    // step is applied last rather than folded into the floor.
    'a step with no multiple between the bounds' => [
        ['type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'multipleOf' => 5],
        'null',
        '0',
    ],
    // A `false` subschema admits nothing at all, so a conjunction carrying one has no instance. Nothing
    // this package writes puts a boolean subschema on a body member; an overlay or a hand-authored
    // component could.
    'a conjunction with a branch nothing satisfies' => [
        ['allOf' => [false, ['type' => 'string']]],
        'null',
        '"string"',
    ],
    // The one difference that runs the other way, and it is core's reach rather than the fill's. Core
    // builds each branch of a conjunction on its own and merges the values, so a bound in ANOTHER branch
    // is invisible to it and it publishes 5 — a value branch two rejects. The fill reduces the branches
    // to one schema first, sees both bounds, and finds nothing satisfies them. Neither answer is true,
    // because the schema admits nothing; no producer mints the shape, and the lint reports it either way.
    'a conjunction whose branches\' bounds cross' => [
        ['allOf' => [['type' => 'integer', 'minimum' => 5], ['maximum' => 3]]],
        '5',
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

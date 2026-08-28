<?php

declare(strict_types=1);

use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\SchemaComparator;
use Docuccino\Core\Diff\SchemaPolarity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Draft\SchemaKeywords;

/**
 * The composition and conditional half of the schema diff. `docuccino:diff --enforce` is a release
 * gate, and it read NONE of `allOf oneOf anyOf not if then else contains propertyNames prefixItems
 * patternProperties dependentSchemas dependentRequired unevaluated* $defs` — so the strictest
 * narrowing the language has, a subschema replaced by `false`, passed as safe under any of them, and
 * via the model's own hydration the same edit surfaced as `schema.type-removed`, which is classed
 * non-breaking. A gate that says "safe" is worse than no gate, because it is trusted.
 *
 * Each keyword's polarity is a recorded decision ({@see SchemaPolarity}) rather than one classification
 * stretched over all of them, so each is pinned here in BOTH directions. The keyword SET is read off
 * {@see SchemaKeywords} rather than listed again, so a keyword the draft model learns fails until
 * somebody decides what it is worth.
 */

/** Every position constant the draft model declares, so a new one is covered without being named. */
function compositionPositions(): array
{
    $positions = [];

    foreach ((new ReflectionClass(SchemaKeywords::class))->getConstants() as $name => $value) {
        if (str_starts_with($name, 'POSITION_') && is_string($value)) {
            $positions[] = $value;
        }
    }

    return $positions;
}

/**
 * Every keyword the draft model gives a subschema position — the set a polarity decision is owed for.
 *
 * @return list<string>
 */
function compositionKeywords(): array
{
    $keywords = [];

    foreach (compositionPositions() as $position) {
        foreach (SchemaKeywords::at($position) as $keyword) {
            $keywords[] = $keyword;
        }
    }

    sort($keywords);

    return $keywords;
}

/** @return array<string, array{string}> */
function compositionKeywordDataset(): array
{
    $rows = [];

    foreach (compositionKeywords() as $keyword) {
        $rows[$keyword] = [$keyword];
    }

    return $rows;
}

/**
 * One schema carrying `$inner` at `$keyword`'s slot: the keyword itself for a single subschema, one
 * member for a map, index 0 for a list, one property's dependency list for `dependentRequired`.
 *
 * @return array<string, mixed>
 */
function compositionProbe(string $keyword, mixed $inner): array
{
    return match (SchemaKeywords::positionOf($keyword)) {
        SchemaKeywords::POSITION_SCHEMA_MAP => [$keyword => ['Inner' => $inner]],
        SchemaKeywords::POSITION_SCHEMA_LIST => [$keyword => [$inner]],
        SchemaKeywords::POSITION_STRING_LIST_MAP => [$keyword => ['a' => $inner]],
        default => [$keyword => $inner],
    };
}

/**
 * Which keywords the two sides disagree about: the ones the draft model positions and nobody has
 * decided, and the ones a decision exists for that the draft model does not position. Both are a
 * decision nobody made — one silently unread, one silently unreachable.
 *
 * @param  list<string>  $positioned
 * @param  list<string>  $decided
 * @return array{list<string>, list<string>}
 */
function compositionPolarityGaps(array $positioned, array $decided): array
{
    return [
        array_values(array_diff($positioned, $decided)),
        array_values(array_diff($decided, $positioned)),
    ];
}

/**
 * Every change one comparison reports, as `code` plus `!` where it is breaking, sorted so the
 * assertion pins the finding rather than the order the arms happen to run in.
 *
 * @param  array<string, mixed>|bool  $old
 * @param  array<string, mixed>|bool  $new
 * @return list<string>
 */
function compositionChanges(array|bool $old, array|bool $new, bool $request): array
{
    $codes = array_map(
        static fn ($change): string => $change->code.($change->breaking ? '!' : ''),
        (new SchemaComparator)->compare($old, $new, 'S', 'sch:v1:0000000000000000', $request),
    );

    sort($codes);

    return $codes;
}

it('records a polarity decision for every keyword the draft model gives a subschema position', function (): void {
    // The guard the fix is owed: the comparator's keyword set is DERIVED from the draft model's own
    // table, so a keyword landing there is a keyword nobody has decided the polarity of until they do.
    $positioned = compositionKeywords();

    [$undecided, $unreachable] = compositionPolarityGaps($positioned, SchemaPolarity::decided());

    expect($undecided)->toBe([], 'no polarity decision recorded for: '.implode(', ', $undecided))
        ->and($unreachable)->toBe([], 'a polarity decision for a keyword the draft model does not position: '.implode(', ', $unreachable))
        // A scan that matches nothing must fail rather than pass forever.
        ->and(count($positioned))->toBeGreaterThanOrEqual(20)
        ->and($positioned)->toContain('allOf', 'anyOf', 'oneOf', 'not', 'if', 'then', 'else', 'contains', 'properties', 'dependentRequired');
});

it('refuses a keyword nobody has decided the polarity of', function (): void {
    // The guard above, EXECUTED rather than asserted: hand it the keyword a future draft model learns
    // and it must name it, in either direction.
    $positioned = compositionKeywords();
    $decided = SchemaPolarity::decided();

    expect(compositionPolarityGaps([...$positioned, 'aKeywordNobodyDecided'], $decided))
        ->toBe([['aKeywordNobodyDecided'], []])
        ->and(compositionPolarityGaps($positioned, [...$decided, 'aDecisionForNoKeyword']))
        ->toBe([[], ['aDecisionForNoKeyword']]);
});

it('reads a keyword nobody has decided conservatively rather than skipping it', function (): void {
    // The runtime half of the same guard, and the reason the split is deliberate: adding a row to the
    // draft model's own table and running this file fails the guard above by NAME while the comparison
    // below still reports the narrowing under that keyword as breaking. The suite tells the author to
    // decide; the gate meanwhile refuses rather than waving it through.
    expect(SchemaPolarity::rule('aKeywordNobodyDecided'))->toBe([
        'polarity' => SchemaPolarity::CONDITIONAL,
        'member' => SchemaPolarity::MEMBER_EMPTY,
        'pairing' => SchemaPolarity::PAIRING_KEY,
        'code' => null,
    ])
        // A list-valued keyword pairs by content, the one rung available without knowing the position.
        ->and(SchemaPolarity::rule('anyOf')['pairing'])->toBe(SchemaPolarity::PAIRING_CONTENT);
});

it('reports a schema that admits nothing at every subschema position', function (string $keyword): void {
    // `false` is the tightest narrowing the language has and the one value no set of keywords spells,
    // so it is the probe every position owes an answer for. Dataset derived from the draft model's
    // table: a position added there fails here until the comparator descends into it.
    [$old, $new] = SchemaKeywords::positionOf($keyword) === SchemaKeywords::POSITION_STRING_LIST_MAP
        ? [compositionProbe($keyword, []), compositionProbe($keyword, ['b'])]
        : [compositionProbe($keyword, ['type' => 'string']), compositionProbe($keyword, false)];

    $onRequest = compositionChanges($old, $new, request: true);
    $onResponse = compositionChanges($old, $new, request: false);

    expect($onRequest)->not->toBe([], $keyword.' reports nothing')
        ->and($onResponse)->not->toBe([], $keyword.' reports nothing on a response')
        // Reported is half of it: a narrowing nobody classes breaking still passes the gate.
        ->and(str_contains(implode(',', [...$onRequest, ...$onResponse]), '!'))
        ->toBeTrue($keyword.' is reported and never breaking')
        // And the probe is what caused it — a comparison of a document with itself says nothing.
        ->and(compositionChanges($old, $old, request: true))->toBe([]);
})->with(compositionKeywordDataset());

it('classifies each composition and conditional keyword in both directions', function (
    array $old,
    array $new,
    array $onRequest,
    array $onResponse,
): void {
    expect(compositionChanges($old, $new, request: true))->toBe($onRequest, 'request')
        ->and(compositionChanges($old, $new, request: false))->toBe($onResponse, 'response');
})->with([
    // `allOf` is an intersection: a branch added narrows, one removed widens. It is the keyword an
    // overlay narrows with, so it is the highest-value half of the whole fix.
    'allOf: branch added' => [
        ['allOf' => [['type' => 'string']]],
        ['allOf' => [['type' => 'string'], ['minLength' => 3]]],
        ['schema.all-of-branch-added!'], ['schema.all-of-branch-added!'],
    ],
    'allOf: branch removed' => [
        ['allOf' => [['type' => 'string'], ['minLength' => 3]]],
        ['allOf' => [['type' => 'string']]],
        ['schema.all-of-branch-removed'], ['schema.all-of-branch-removed'],
    ],
    'allOf: branch narrowed' => [
        ['allOf' => [['type' => ['string', 'integer']]]],
        ['allOf' => [['type' => 'string']]],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    'allOf: branch widened' => [
        ['allOf' => [['type' => 'string']]],
        ['allOf' => [['type' => ['string', 'integer']]]],
        ['schema.type-widened'], ['schema.type-widened'],
    ],
    // A union: a branch removed narrows either way, while one ADDED widens what a request accepts and
    // hands a response reader a shape it has no case for — the `schema.enum-value-added` argument.
    'anyOf: branch added' => [
        ['anyOf' => [['$ref' => '#/components/schemas/A']]],
        ['anyOf' => [['$ref' => '#/components/schemas/A'], ['type' => 'null']]],
        ['schema.any-of-branch-added'], ['schema.any-of-branch-added!'],
    ],
    'anyOf: branch removed' => [
        ['anyOf' => [['$ref' => '#/components/schemas/A'], ['type' => 'null']]],
        ['anyOf' => [['$ref' => '#/components/schemas/A']]],
        ['schema.any-of-branch-removed!'], ['schema.any-of-branch-removed!'],
    ],
    'oneOf: branch added' => [
        ['oneOf' => [['$ref' => '#/components/schemas/A']]],
        ['oneOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
        ['schema.one-of-branch-added'], ['schema.one-of-branch-added!'],
    ],
    'oneOf: branch removed' => [
        ['oneOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
        ['oneOf' => [['$ref' => '#/components/schemas/A']]],
        ['schema.one-of-branch-removed!'], ['schema.one-of-branch-removed!'],
    ],
    // `not` INVERTS, which is why nothing tries to carry the child's verdict up: a type widened under
    // `not` narrows the parent. The code still names what moved; the verdict is conservative.
    'not: added' => [
        ['type' => 'string'],
        ['type' => 'string', 'not' => ['const' => 'x']],
        ['schema.not-added!'], ['schema.not-added!'],
    ],
    'not: removed' => [
        ['type' => 'string', 'not' => ['const' => 'x']],
        ['type' => 'string'],
        ['schema.not-removed'], ['schema.not-removed'],
    ],
    'not: subschema widened' => [
        ['not' => ['type' => 'string']],
        ['not' => ['type' => ['string', 'integer']]],
        ['schema.type-widened!'], ['schema.type-widened!'],
    ],
    'not: subschema narrowed' => [
        ['not' => ['type' => ['string', 'integer']]],
        ['not' => ['type' => 'string']],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    // `if` is the one member of its family with no polarity at all — it moves instances between the
    // `then` and `else` branches — so both directions are breaking by decision.
    'if: added' => [
        ['type' => 'object'],
        ['type' => 'object', 'if' => ['required' => ['a']]],
        ['schema.required-added!'], ['schema.required-added!'],
    ],
    'if: removed' => [
        ['type' => 'object', 'if' => ['required' => ['a']]],
        ['type' => 'object'],
        ['schema.required-removed!'], ['schema.required-removed!'],
    ],
    // `then` and `else` are DIRECT, which is a correction to the family reading: `(A ∧ B) ∨ ¬A` and
    // `A ∨ (¬A ∧ C)` both narrow when B or C narrows, so their verdicts are real rather than conservative.
    'then: narrowed' => [
        ['if' => ['required' => ['a']], 'then' => ['type' => ['string', 'integer']]],
        ['if' => ['required' => ['a']], 'then' => ['type' => 'string']],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    'then: widened' => [
        ['if' => ['required' => ['a']], 'then' => ['type' => 'string']],
        ['if' => ['required' => ['a']], 'then' => ['type' => ['string', 'integer']]],
        ['schema.type-widened'], ['schema.type-widened'],
    ],
    'else: narrowed' => [
        ['if' => ['required' => ['a']], 'else' => ['type' => ['string', 'integer']]],
        ['if' => ['required' => ['a']], 'else' => ['type' => 'string']],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    'else: widened' => [
        ['if' => ['required' => ['a']], 'else' => ['type' => 'string']],
        ['if' => ['required' => ['a']], 'else' => ['type' => ['string', 'integer']]],
        ['schema.type-widened'], ['schema.type-widened'],
    ],
    // `contains` demands a match, so no `contains` and `contains: {}` say opposite things and presence
    // is a claim of its own.
    'contains: added' => [
        ['type' => 'array'],
        ['type' => 'array', 'contains' => ['type' => 'string']],
        ['schema.contains-added!'], ['schema.contains-added!'],
    ],
    'contains: removed' => [
        ['type' => 'array', 'contains' => ['type' => 'string']],
        ['type' => 'array'],
        ['schema.contains-removed'], ['schema.contains-removed'],
    ],
    // …but at `minContains: 0` it asserts nothing, so it can arrive without narrowing anything.
    'contains: added with minContains 0' => [
        ['type' => 'array'],
        ['type' => 'array', 'contains' => ['type' => 'string'], 'minContains' => 0],
        ['schema.contains-added'], ['schema.contains-added'],
    ],
    'contains: subschema narrowed' => [
        ['contains' => ['type' => ['string', 'integer']]],
        ['contains' => ['type' => 'string']],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    // A tuple slot is its own contract, so the index pairs: appending one constrains a position that
    // was unconstrained, and reordering two is two positions changing.
    'prefixItems: slot added' => [
        ['prefixItems' => [['type' => 'string']]],
        ['prefixItems' => [['type' => 'string'], ['type' => 'integer']]],
        ['schema.type-added!'], ['schema.type-added!'],
    ],
    'prefixItems: slot removed' => [
        ['prefixItems' => [['type' => 'string'], ['type' => 'integer']]],
        ['prefixItems' => [['type' => 'string']]],
        ['schema.type-removed'], ['schema.type-removed'],
    ],
    'prefixItems: reordered' => [
        ['prefixItems' => [['type' => 'string'], ['type' => 'integer']]],
        ['prefixItems' => [['type' => 'integer'], ['type' => 'string']]],
        ['schema.type-changed!', 'schema.type-changed!'], ['schema.type-changed!', 'schema.type-changed!'],
    ],
    // Every other map and single position: an absent member says what the empty schema says there, so
    // a member arriving IS the constraint arriving and needs no code of its own.
    'patternProperties: member added' => [
        ['type' => 'object'],
        ['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'string']]],
        ['schema.type-added!'], ['schema.type-added!'],
    ],
    'patternProperties: member removed' => [
        ['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'string']]],
        ['type' => 'object'],
        ['schema.type-removed'], ['schema.type-removed'],
    ],
    'dependentSchemas: member added' => [
        ['type' => 'object'],
        ['type' => 'object', 'dependentSchemas' => ['a' => ['required' => ['b']]]],
        ['schema.required-added!'], ['schema.required-added'],
    ],
    'propertyNames: narrowed' => [
        ['propertyNames' => ['type' => ['string', 'integer']]],
        ['propertyNames' => ['type' => 'string']],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    'unevaluatedProperties: closed' => [
        ['type' => 'object'],
        ['type' => 'object', 'unevaluatedProperties' => false],
        ['schema.always-invalid-added!'], ['schema.always-invalid-added!'],
    ],
    'unevaluatedItems: closed' => [
        ['type' => 'array'],
        ['type' => 'array', 'unevaluatedItems' => false],
        ['schema.always-invalid-added!'], ['schema.always-invalid-added!'],
    ],
    'additionalItems: closed' => [
        ['type' => 'array'],
        ['type' => 'array', 'additionalItems' => false],
        ['schema.always-invalid-added!'], ['schema.always-invalid-added!'],
    ],
    'contentSchema: narrowed' => [
        ['type' => 'string', 'contentSchema' => ['type' => ['string', 'integer']]],
        ['type' => 'string', 'contentSchema' => ['type' => 'string']],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    // `dependentRequired` narrows what a request accepts exactly as `required` does, and is reported
    // the same way — a report rather than a verdict on the response side.
    'dependentRequired: dependency added' => [
        ['type' => 'object'],
        ['type' => 'object', 'dependentRequired' => ['a' => ['b']]],
        ['schema.dependent-required-added!'], ['schema.dependent-required-added'],
    ],
    'dependentRequired: dependency removed' => [
        ['type' => 'object', 'dependentRequired' => ['a' => ['b']]],
        ['type' => 'object'],
        ['schema.dependent-required-removed'], ['schema.dependent-required-removed'],
    ],
    'dependentRequired: one swapped for another' => [
        ['dependentRequired' => ['a' => ['b']]],
        ['dependentRequired' => ['a' => ['c']]],
        ['schema.dependent-required-added!', 'schema.dependent-required-removed'],
        ['schema.dependent-required-added', 'schema.dependent-required-removed'],
    ],
    // A `$defs` member is a STORE rather than an assertion: its polarity is whatever the `$ref`s
    // naming it are worth, which this comparison does not resolve — so arriving is nothing and leaving
    // may dangle a ref.
    '$defs: member added' => [
        ['type' => 'object'],
        ['type' => 'object', '$defs' => ['X' => ['type' => 'string']]],
        ['schema.definition-added'], ['schema.definition-added'],
    ],
    '$defs: member removed' => [
        ['type' => 'object', '$defs' => ['X' => ['type' => 'string']]],
        ['type' => 'object'],
        ['schema.definition-removed!'], ['schema.definition-removed!'],
    ],
    '$defs: member widened' => [
        ['$defs' => ['X' => ['type' => 'string']]],
        ['$defs' => ['X' => ['type' => ['string', 'integer']]]],
        ['schema.type-widened!'], ['schema.type-widened!'],
    ],
    'definitions: draft-07 spelling reads the same' => [
        ['definitions' => ['X' => ['type' => 'string']]],
        ['definitions' => ['X' => ['type' => ['string', 'integer']]]],
        ['schema.type-widened!'], ['schema.type-widened!'],
    ],
]);

it('leaves an annotation edit under an indeterminate position alone', function (array $old, array $new): void {
    // The one exception to the conservative verdict: an annotation-only keyword says what a value MEANS
    // and nothing about what it may be, so editing one gates nothing under any versioning policy — at
    // `not` and `$defs` as much as anywhere else. Without this a doc edit inside a `not` fails a
    // release gate, which is how a channel stops being read.
    expect(compositionChanges($old, $new, request: true))->toBe(['schema.annotation-changed'])
        ->and(compositionChanges($old, $new, request: false))->toBe(['schema.annotation-changed']);
})->with([
    'under not' => [
        ['not' => ['type' => 'string', 'description' => 'was']],
        ['not' => ['type' => 'string', 'description' => 'now']],
    ],
    'under if' => [
        ['if' => ['type' => 'string', 'description' => 'was']],
        ['if' => ['type' => 'string', 'description' => 'now']],
    ],
    'under $defs' => [
        ['$defs' => ['X' => ['type' => 'string', 'title' => 'Was']]],
        ['$defs' => ['X' => ['type' => 'string', 'title' => 'Now']]],
    ],
]);

it('pairs a list branch by what it is, never by where it sits', function (): void {
    // Without an identity rule, reordering a union reads as rewriting every branch — noise that would
    // swamp every real finding. The ladder is ComponentNames' applied to a list: identity, then the
    // component a branch names, then its content.
    $refs = static fn (string ...$names): array => ['oneOf' => array_map(
        static fn (string $name): array => ['$ref' => '#/components/schemas/'.$name],
        $names,
    )];

    expect(compositionChanges($refs('A', 'B', 'C'), $refs('C', 'A', 'B'), request: true))->toBe([])
        ->and(compositionChanges($refs('A', 'B'), $refs('A', 'B'), request: false))->toBe([])
        // A branch replaced is two branches, never one edited: pairing the leftovers would publish
        // `schema.ref-changed` — non-breaking — over a union branch no existing reader has a case for.
        ->and(compositionChanges($refs('A', 'B'), $refs('A', 'C'), request: false))
        ->toBe(['schema.one-of-branch-added!', 'schema.one-of-branch-removed!'])
        // On a request the branch that WENT is still breaking — a writer's valid body is now refused —
        // and only the one that arrived stands down.
        ->and(compositionChanges($refs('A', 'B'), $refs('A', 'C'), request: true))
        ->toBe(['schema.one-of-branch-added', 'schema.one-of-branch-removed!']);
});

it('pairs an identified branch that both moved and changed', function (): void {
    // The top rung: a branch carrying a Docuccino id is the same branch wherever it sits and whatever
    // else moved inside it, which is the identity every other pairing in the diff already runs on.
    $branch = static fn (string $id, string $type): array => [
        'x-docuccino' => ['id' => $id],
        'type' => $type,
    ];

    $old = ['anyOf' => [$branch('sch:v1:aaaaaaaaaaaaaaaa', 'string'), $branch('sch:v1:bbbbbbbbbbbbbbbb', 'integer')]];
    $new = ['anyOf' => [$branch('sch:v1:bbbbbbbbbbbbbbbb', 'integer'), $branch('sch:v1:aaaaaaaaaaaaaaaa', 'boolean')]];

    $changes = (new SchemaComparator)->compare($old, $new, 'S', 'sch:v1:0000000000000000', request: true);

    expect(array_map(static fn ($c): string => $c->code.' @'.$c->path, $changes))
        ->toBe(['schema.type-changed @S.anyOf.1.type']);
});

it('reads an inline branch edited in place as one branch changing', function (): void {
    // The last rung, and the only inexact one: ONE branch left over on each side, neither naming a
    // component, is one inline branch edited — the `allOf: [$ref, {inline extension}]` shape a
    // problem+json body is published as. Reporting it as a branch gone and another arrived would fail
    // a release gate over a property added.
    $body = static fn (array $properties): array => ['allOf' => [
        ['$ref' => '#/components/schemas/ProblemDetails'],
        ['type' => 'object', 'properties' => $properties],
    ]];

    $changes = (new SchemaComparator)->compare(
        $body(['detail' => ['type' => 'string']]),
        $body(['detail' => ['type' => 'string'], 'pointer' => ['type' => 'string']]),
        'S',
        'sch:v1:0000000000000000',
        request: false,
    );

    expect(array_map(static fn ($c): string => $c->code.($c->breaking ? '!' : '').' @'.$c->path, $changes))
        ->toBe(['schema.property-added @S.allOf.1.properties.pointer']);
});

it('reads the contains bounds beside the contains they bound', function (array $old, array $new, array $expected): void {
    expect(compositionChanges($old, $new, request: true))->toBe($expected)
        // The bounds constrain the array either way it is read, so neither direction differs.
        ->and(compositionChanges($old, $new, request: false))->toBe($expected);
})->with([
    'minContains raised' => [
        ['contains' => ['type' => 'string'], 'minContains' => 1],
        ['contains' => ['type' => 'string'], 'minContains' => 2],
        ['schema.contains-bound-narrowed!'],
    ],
    'minContains lowered' => [
        ['contains' => ['type' => 'string'], 'minContains' => 2],
        ['contains' => ['type' => 'string'], 'minContains' => 1],
        ['schema.contains-bound-widened'],
    ],
    // Absent is 1 — the keyword's own default, which is what makes `minContains: 0` a real statement.
    'minContains dropped to zero' => [
        ['contains' => ['type' => 'string']],
        ['contains' => ['type' => 'string'], 'minContains' => 0],
        ['schema.contains-bound-widened'],
    ],
    'minContains restated' => [
        ['contains' => ['type' => 'string']],
        ['contains' => ['type' => 'string'], 'minContains' => 1],
        [],
    ],
    // No cap is no bound at all, so one arriving narrows however high it is set.
    'maxContains capped' => [
        ['contains' => ['type' => 'string']],
        ['contains' => ['type' => 'string'], 'maxContains' => 9],
        ['schema.contains-bound-narrowed!'],
    ],
    'maxContains lowered' => [
        ['contains' => ['type' => 'string'], 'maxContains' => 9],
        ['contains' => ['type' => 'string'], 'maxContains' => 2],
        ['schema.contains-bound-narrowed!'],
    ],
    'maxContains raised' => [
        ['contains' => ['type' => 'string'], 'maxContains' => 2],
        ['contains' => ['type' => 'string'], 'maxContains' => 9],
        ['schema.contains-bound-widened'],
    ],
    'maxContains uncapped' => [
        ['contains' => ['type' => 'string'], 'maxContains' => 2],
        ['contains' => ['type' => 'string']],
        ['schema.contains-bound-widened'],
    ],
    // Both are inert with no `contains` beside them, and where `contains` itself moves, THAT is the
    // change: a bound reported next to it would be a second finding for one edit.
    'a bound with no contains at all' => [
        ['type' => 'array', 'minContains' => 1],
        ['type' => 'array', 'minContains' => 4],
        [],
    ],
    'a bound arriving with the contains it bounds' => [
        ['type' => 'array'],
        ['type' => 'array', 'contains' => ['type' => 'string'], 'minContains' => 4],
        ['schema.contains-added!'],
    ],
]);

it('names a union branch through the path a diff actually runs', function (): void {
    // The end-to-end claim: this is the edit `--enforce` used to pass as safe. A response that could
    // return a Widget stops being able to, and the gate now says so, at the path a reviewer can find.
    $document = static fn (array $branches): UirDocument => UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/things' => ['get' => [
            'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
            'operationId' => 'things.index',
            'responses' => ['200' => [
                'x-docuccino' => ['id' => 'res:v1:bbbbbbbbbbbbbbbb'],
                'description' => 'ok',
                'content' => ['application/json' => ['schema' => ['oneOf' => $branches]]],
            ]],
        ]]],
        'components' => ['schemas' => [
            'Gadget' => ['x-docuccino' => ['id' => 'sch:v1:cccccccccccccccc'], 'type' => 'object'],
            'Widget' => ['x-docuccino' => ['id' => 'sch:v1:dddddddddddddddd'], 'type' => 'object'],
        ]],
    ]);

    $both = [['$ref' => '#/components/schemas/Gadget'], ['$ref' => '#/components/schemas/Widget']];
    $changeset = (new DocumentDiffer)->diff($document($both), $document([$both[0]]));

    expect(array_map(static fn ($c): string => $c->code, $changeset->changes))
        ->toContain('schema.one-of-branch-removed')
        ->and($changeset->isBreaking())->toBeTrue();

    foreach ($changeset->changes as $change) {
        if ($change->code === 'schema.one-of-branch-removed') {
            expect($change->path)->toBe('GET /things responses 200 application/json schema.oneOf.1');
        }
    }
});

it('reads a value that is no list as no branches at all', function (mixed $garbage): void {
    // A comparison runs on whatever an artifact holds, and an `allOf` that is not a list is not a schema
    // either — so it reads as absent, which is the widening the canonicalizer publishes for it. Reading
    // it as ONE branch would report a narrowing that is not in the document.
    expect(compositionChanges(['allOf' => $garbage], ['allOf' => $garbage], request: true))->toBe([])
        ->and(compositionChanges(['allOf' => [['type' => 'string']]], ['allOf' => $garbage], request: true))
        ->toBe(['schema.all-of-branch-removed'])
        ->and(compositionChanges(['allOf' => $garbage], ['allOf' => [['type' => 'string']]], request: true))
        ->toBe(['schema.all-of-branch-added!']);
})->with([
    'an object' => [['not' => 'a list']],
    'a string' => ['nonsense'],
    'a number' => [7],
    'null' => [null],
]);

it('reads a map position that is no map as carrying no members', function (mixed $garbage): void {
    // The same for the map positions and for `dependentRequired`, whose members are string lists: a
    // member nothing can read is a member nobody wrote.
    expect(compositionChanges(['patternProperties' => $garbage], ['patternProperties' => $garbage], request: true))->toBe([])
        ->and(compositionChanges(['dependentRequired' => $garbage], ['dependentRequired' => $garbage], request: true))->toBe([])
        // And a dependency list that is no list of strings leaves the property with no dependencies.
        ->and(compositionChanges(['dependentRequired' => ['a' => $garbage]], ['dependentRequired' => ['a' => ['b']]], request: true))
        ->toBe(['schema.dependent-required-added!']);
})->with([
    'a map of non-schemas' => [['x' => 7]],
    'a string' => ['nonsense'],
    'a number' => [7],
    'null' => [null],
]);

it('never lets a position with no computable polarity report a change as safe', function (): void {
    // The decision the whole fix turns on, swept rather than sampled: where the direction cannot be
    // computed the verdict is breaking, on BOTH sides, for every edit shape a subschema can take. The
    // set is read off the rules table, so a keyword moved to CONDITIONAL is covered without being named.
    $conditional = array_values(array_filter(
        SchemaPolarity::decided(),
        static fn (string $keyword): bool => SchemaPolarity::rule($keyword)['polarity'] === SchemaPolarity::CONDITIONAL,
    ));

    // Anti-vacuity: an empty set would agree with everything below and prove nothing.
    expect($conditional)->toContain('if', '$defs', 'definitions');

    $edits = [
        'narrowed' => ['type' => 'string'],
        'widened' => ['type' => ['string', 'integer', 'null']],
        'retyped' => ['type' => 'integer'],
        'untyped' => [],
        'admits nothing' => false,
        'admits everything' => true,
        'constrained' => ['type' => ['string', 'integer'], 'required' => ['a']],
        'unconstrained' => ['type' => ['string', 'integer'], 'enum' => null],
    ];

    foreach ($conditional as $keyword) {
        foreach ($edits as $label => $inner) {
            $old = compositionProbe($keyword, ['type' => ['string', 'integer']]);
            $new = compositionProbe($keyword, $inner);

            foreach ([true, false] as $request) {
                foreach ((new SchemaComparator)->compare($old, $new, 'S', 'sch:v1:0000000000000000', $request) as $change) {
                    expect($change->breaking)->toBeTrue(
                        $keyword.' · '.$label.' · '.($request ? 'request' : 'response').' · '.$change->code,
                    );
                }
            }
        }
    }
});

it('states the one place an indeterminate position stands a change down, and why', function (): void {
    // Two exceptions, both decisions rather than gaps. An annotation-only edit moves no contract at any
    // position, so forcing it breaking would fail a gate over a rewritten description — which is how a
    // channel stops being read. And a `$defs` member ARRIVING is not an assertion arriving: nothing can
    // name a definition that did not also change, and whatever named it is reported where it changed.
    expect(compositionChanges(
        ['$defs' => ['X' => ['type' => 'string', 'description' => 'was']]],
        ['$defs' => ['X' => ['type' => 'string', 'description' => 'now']]],
        request: true,
    ))->toBe(['schema.annotation-changed'])
        ->and(compositionChanges(['type' => 'object'], ['type' => 'object', '$defs' => ['X' => ['type' => 'string']]], request: true))
        ->toBe(['schema.definition-added'])
        // Leaving is the half that gates: a `$ref` naming the member is left pointing at nothing.
        ->and(compositionChanges(['type' => 'object', '$defs' => ['X' => ['type' => 'string']]], ['type' => 'object'], request: true))
        ->toBe(['schema.definition-removed!']);
});

it('answers the presence verdict for every member kind there is', function (): void {
    // The lookup table behind every `-added`/`-removed` code, over EVERY kind rather than a sample —
    // the kinds are read off the class, so one added with no verdict of its own shows up here.
    $verdicts = [
        // A constraint arriving narrows whichever way the schema is read; going widens.
        SchemaPolarity::MEMBER_CONSTRAINT => ['request' => [true, false], 'response' => [true, false]],
        // `contains` arriving narrows only while it asserts something ($asserts, from `minContains`).
        SchemaPolarity::MEMBER_BOUNDED => ['request' => [true, false], 'response' => [true, false]],
        // A union branch going narrows either way; one arriving is safe for a writer and hands a reader
        // a shape it has no case for.
        SchemaPolarity::MEMBER_UNION => ['request' => [false, true], 'response' => [true, true]],
        // A definition arriving asserts nothing; one leaving may dangle a `$ref`.
        SchemaPolarity::MEMBER_STORE => ['request' => [false, true], 'response' => [false, true]],
        // The kinds with a comparison of their own never reach the table, and the kind with no presence
        // decision at all is the one that must not guess: breaking, both directions, both sides.
        SchemaPolarity::MEMBER_EMPTY => ['request' => [true, true], 'response' => [true, true]],
        SchemaPolarity::MEMBER_PROPERTY => ['request' => [true, true], 'response' => [true, true]],
        SchemaPolarity::MEMBER_REQUIRED => ['request' => [true, true], 'response' => [true, true]],
    ];

    // The dataset only proves the rows it lists, so the kinds come from the class and this fails short.
    // Compared as sets: which kinds exist is the fact, and the order they are declared in is not one.
    $listed = array_keys($verdicts);
    $kinds = SchemaPolarity::memberKinds();
    sort($listed);
    sort($kinds);

    expect($listed)->toBe($kinds)
        ->and(count($kinds))->toBeGreaterThanOrEqual(5);

    foreach ($verdicts as $member => $expected) {
        foreach (['request' => true, 'response' => false] as $side => $request) {
            [$arriving, $leaving] = $expected[$side];

            expect(SchemaPolarity::presenceIsBreaking($member, arriving: true, request: $request, asserts: true))
                ->toBe($arriving, $member.' arriving on a '.$side)
                ->and(SchemaPolarity::presenceIsBreaking($member, arriving: false, request: $request, asserts: true))
                ->toBe($leaving, $member.' leaving on a '.$side);
        }
    }

    // The unknown-entry contract: a kind nobody has given a verdict is not waved through.
    expect(SchemaPolarity::presenceIsBreaking('aMemberKindNobodyDecided', arriving: true, request: true, asserts: true))->toBeTrue()
        ->and(SchemaPolarity::presenceIsBreaking('aMemberKindNobodyDecided', arriving: false, request: false, asserts: false))->toBeTrue()
        // …and `contains` is the one row `$asserts` moves: at `minContains: 0` it arrives asserting nothing.
        ->and(SchemaPolarity::presenceIsBreaking(SchemaPolarity::MEMBER_BOUNDED, arriving: true, request: true, asserts: false))->toBeFalse();
});

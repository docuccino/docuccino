<?php

declare(strict_types=1);

use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\ReadingKind;
use Docuccino\Core\Diff\RefinementMove;
use Docuccino\Core\Diff\SchemaComparator;
use Docuccino\Core\Diff\SchemaPolarity;
use Docuccino\Core\Diff\SchemaReading;
use Docuccino\Core\Diff\SchemaRefinement;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Draft\SchemaKeywords;

/**
 * The third of the three sets a schema diff owes a decision for, and the lesson of the two before it:
 * the composition guard is keyed to the subschema positions and the refinement guard to the refinements,
 * so five contract-bearing keywords — `discriminator`, `nullable`, `$id`, `$anchor`, `$schema` — sat in
 * the gap between them and were read by NOTHING. Two derived guards did not add up to coverage, because
 * each was keyed to its own subset.
 *
 * So the assertion that matters here is not this table's own completeness but the UNION: every keyword
 * the draft model knows is decided by one of the three, and a keyword that joins the model with no
 * decision anywhere fails the suite rather than passing `--enforce` as safe. `discriminator` is why it
 * matters most — it names the property a client switches on and the subschema each tag deserialises as,
 * so a repointed mapping entry leaves the payload valid, the client compiled, and the object it builds
 * the wrong type.
 */

/** Every keyword the draft model knows that neither sibling table decides — the set this one owes a row for. */
function readingKeywords(): array
{
    $keywords = array_values(array_filter(
        array_keys(SchemaKeywords::classification()),
        static fn (string $keyword): bool => SchemaKeywords::positionOf($keyword) === null && ! SchemaKeywords::isRefinement($keyword),
    ));
    sort($keywords);

    return $keywords;
}

/** Every keyword the draft model knows at all — what the three tables together are held against. */
function readingKnownKeywords(): array
{
    $keywords = array_keys(SchemaKeywords::classification());
    sort($keywords);

    return $keywords;
}

/** Every keyword any of the three decision tables answers for, deduped and sorted. */
function readingDecidedEverywhere(): array
{
    $decided = [...SchemaPolarity::decided(), ...SchemaRefinement::decided(), ...SchemaReading::decided()];
    $decided = array_values(array_unique($decided));
    sort($decided);

    return $decided;
}

/**
 * Which keywords two sides disagree about: the ones nobody decided, and the ones a decision exists for
 * that the draft model does not know. Both are a decision nobody made — one silently unread, one
 * silently unreachable.
 *
 * @param  list<string>  $known
 * @param  list<string>  $decided
 * @return array{list<string>, list<string>}
 */
function readingDecisionGaps(array $known, array $decided): array
{
    return [
        array_values(array_diff($known, $decided)),
        array_values(array_diff($decided, $known)),
    ];
}

/**
 * Every change one comparison reports, as `code` plus `!` where it is breaking, sorted so the assertion
 * pins the finding rather than the order the arms happen to run in.
 *
 * @param  array<string, mixed>  $old
 * @param  array<string, mixed>  $new
 * @return list<string>
 */
function readingChanges(array $old, array $new, bool $request): array
{
    $codes = array_map(
        static fn ($change): string => $change->code.($change->breaking ? '!' : ''),
        (new SchemaComparator)->compare($old, $new, 'S', 'sch:v1:0000000000000000', $request),
    );

    sort($codes);

    return $codes;
}

/**
 * A schema carrying a Discriminator Object over a union — the shape a polymorphic relation publishes.
 *
 * @param  array<string, string>  $mapping
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function readingDiscriminated(array $mapping, string $property = 'type', array $extra = []): array
{
    return [
        'oneOf' => [['$ref' => '#/components/schemas/Invoice'], ['$ref' => '#/components/schemas/Subscription']],
        'discriminator' => ['propertyName' => $property, 'mapping' => $mapping] + $extra,
    ];
}

it('records a reading decision for every keyword neither sibling table decides', function (): void {
    $known = readingKeywords();

    [$undecided, $unreachable] = readingDecisionGaps($known, SchemaReading::decided());

    expect($undecided)->toBe([], 'no reading decision recorded for: '.implode(', ', $undecided))
        ->and($unreachable)->toBe([], 'a reading decision for a keyword no table owes one: '.implode(', ', $unreachable))
        // A scan that matches nothing must fail rather than pass forever.
        ->and(count($known))->toBeGreaterThanOrEqual(15)
        ->and($known)->toContain('discriminator', 'nullable', '$id', '$anchor', '$schema');
});

it('accounts for every keyword the draft model knows across all three decision tables', function (): void {
    // The guard the two before it could not be: each was keyed to its own subset, so a keyword in neither
    // subset was invisible to both. This one is keyed to the model itself, and the three tables together
    // answer for it or the suite fails.
    $known = readingKnownKeywords();

    [$undecided, $unreachable] = readingDecisionGaps($known, readingDecidedEverywhere());

    expect($undecided)->toBe([], 'no table decides: '.implode(', ', $undecided))
        ->and($unreachable)->toBe([], 'decided by a table but unknown to the draft model: '.implode(', ', $unreachable))
        ->and(count($known))->toBeGreaterThanOrEqual(55)
        // …and the three sets are a partition rather than three overlapping opinions: a keyword this table
        // decides is one no sibling reads, so nothing is answered twice by tables that could disagree.
        ->and(array_values(array_intersect(SchemaReading::decided(), [...SchemaPolarity::decided(), ...SchemaRefinement::decided()])))
        ->toBe([]);
});

it('knows exactly the keywords it classifies, so the union is held against the model itself', function (): void {
    // The set the guard above reads is `knows()`, and a second reading of the same three lists is exactly
    // the kind of copy that goes short: pin the two against each other, in both directions.
    foreach (readingKnownKeywords() as $keyword) {
        expect(SchemaKeywords::knows($keyword))->toBeTrue($keyword.' is classified but unknown');
    }

    expect(SchemaKeywords::knows('aKeywordNoDraftModelKnows'))->toBeFalse()
        ->and(SchemaKeywords::knows('x-vendor'))->toBeFalse();
});

it('refuses a keyword nobody has decided, in either direction', function (): void {
    // The two guards above, EXECUTED rather than asserted: hand each the keyword a future draft model
    // learns and it must name it — the third table on its own, and the union across all three, which is
    // the failure two derived guards could not produce between them.
    $known = readingKeywords();
    $decided = SchemaReading::decided();

    expect(readingDecisionGaps([...$known, 'aKeywordNobodyDecided'], $decided))
        ->toBe([['aKeywordNobodyDecided'], []])
        ->and(readingDecisionGaps($known, [...$decided, 'aDecisionForNoKeyword']))
        ->toBe([[], ['aDecisionForNoKeyword']])
        ->and(readingDecisionGaps([...readingKnownKeywords(), 'aKeywordNoTableDecides'], readingDecidedEverywhere()))
        ->toBe([['aKeywordNoTableDecides'], []]);
});

it('reads a keyword nobody has decided conservatively rather than skipping it', function (): void {
    // The runtime half of the same guard, and the reason the split is deliberate: adding a keyword to the
    // draft model's own classification and running this file fails the guards above by NAME while the
    // comparison still refuses the change. The suite tells the author to decide; the gate does not guess.
    expect(SchemaReading::rule('aKeywordNobodyDecided'))->toBe(ReadingKind::Undecided)
        // …and this table is not the one that decides a keyword the model does not know, nor one a
        // sibling reads: a position, a refinement and a vendor extension are each somebody else's answer.
        ->and(SchemaReading::kindOf('aKeywordNobodyDecided'))->toBeNull()
        ->and(SchemaReading::kindOf('allOf'))->toBeNull()
        ->and(SchemaReading::kindOf('maxLength'))->toBeNull()
        ->and(SchemaReading::kindOf('$defs'))->toBeNull()
        ->and(SchemaReading::kindOf('discriminator'))->toBe(ReadingKind::Discriminator);
});

it('records the reading of every keyword it decides', function (): void {
    // The whole table, every row, because a mapping table proves only the rows a dataset lists — and the
    // set is read off the source of truth, so a keyword added there fails this until it has a row.
    $rules = [
        'discriminator' => ReadingKind::Discriminator,
        'nullable' => ReadingKind::Nullability,
        '$id' => ReadingKind::Identity,
        '$anchor' => ReadingKind::Identity,
        '$schema' => ReadingKind::Dialect,
        '$ref' => ReadingKind::Elsewhere,
        'type' => ReadingKind::Elsewhere,
        'required' => ReadingKind::Elsewhere,
        '$comment' => ReadingKind::Annotation,
        'description' => ReadingKind::Annotation,
        'example' => ReadingKind::Annotation,
        'examples' => ReadingKind::Annotation,
        'externalDocs' => ReadingKind::Annotation,
        'title' => ReadingKind::Annotation,
        'x-docuccino' => ReadingKind::Unread,
        'default' => ReadingKind::Unread,
        'readOnly' => ReadingKind::Unread,
        'writeOnly' => ReadingKind::Unread,
        'deprecated' => ReadingKind::Unread,
    ];

    $listed = array_keys($rules);
    sort($listed);

    expect($listed)->toBe(readingKeywords());

    foreach ($rules as $keyword => $kind) {
        expect(SchemaReading::rule($keyword))->toBe($kind, $keyword.' reading')
            ->and(SchemaReading::kindOf($keyword))->toBe($kind, $keyword.' reading, through the filter');
    }
});

it('calls a keyword an annotation exactly where the draft model does', function (): void {
    // Two lists saying the same thing is how one goes short: the rows that say "read as an annotation"
    // are held against the annotation-only set itself, in both directions.
    $annotations = array_values(array_filter(
        readingKeywords(),
        static fn (string $keyword): bool => SchemaReading::rule($keyword) === ReadingKind::Annotation,
    ));
    $actual = SchemaKeywords::annotationOnly();
    sort($actual);

    expect($annotations)->toBe($actual)
        ->and(count($actual))->toBeGreaterThanOrEqual(5);
});

it('classifies every discriminator edit in both directions', function (array $old, array $new, array $onRequest, array $onResponse): void {
    expect(readingChanges($old, $new, request: true))->toBe($onRequest, 'request')
        ->and(readingChanges($old, $new, request: false))->toBe($onResponse, 'response');
})->with([
    // The edit the issue was opened on: the tag still validates, the client still compiles, and the
    // object it builds is the wrong type.
    'a mapping entry repointed' => [
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice']),
        readingDiscriminated(['invoice' => '#/components/schemas/Subscription']),
        ['schema.discriminator-changed!'], ['schema.discriminator-changed!'],
    ],
    'a mapping entry removed' => [
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice', 'subscription' => '#/components/schemas/Subscription']),
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice']),
        ['schema.discriminator-narrowed!'], ['schema.discriminator-narrowed!'],
    ],
    // A branch the client has no case for, which is `schema.enum-value-added`'s argument exactly.
    'a mapping entry added' => [
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice']),
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice', 'subscription' => '#/components/schemas/Subscription']),
        ['schema.discriminator-widened'], ['schema.discriminator-widened!'],
    ],
    // No widening reading exists: every client reads a property that is no longer the tag.
    'the tag property renamed' => [
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice'], 'type'),
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice'], 'kind'),
        ['schema.discriminator-changed!'], ['schema.discriminator-changed!'],
    ],
    // A mapping is a MAP, so its entries pair by key and the order they were written in is not a change.
    'a mapping reordered' => [
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice', 'subscription' => '#/components/schemas/Subscription']),
        readingDiscriminated(['subscription' => '#/components/schemas/Subscription', 'invoice' => '#/components/schemas/Invoice']),
        [], [],
    ],
    // The keyword itself: arriving, a client that could send any branch must now tag it; leaving, the
    // schema widens while a response reader loses the tag it was switching on.
    'the discriminator arriving' => [
        ['oneOf' => [['$ref' => '#/components/schemas/Invoice'], ['$ref' => '#/components/schemas/Subscription']]],
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice']),
        ['schema.discriminator-added!'], ['schema.discriminator-added!'],
    ],
    'the discriminator leaving' => [
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice']),
        ['oneOf' => [['$ref' => '#/components/schemas/Invoice'], ['$ref' => '#/components/schemas/Subscription']]],
        ['schema.discriminator-removed'], ['schema.discriminator-removed!'],
    ],
    // Every member is compared, not the two this was written against — so a member OpenAPI adds later is
    // read the day it appears rather than joining the gap this table exists to close.
    'a member the diff was not written against' => [
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice'], 'type', ['defaultMapping' => '#/components/schemas/Invoice']),
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice'], 'type', ['defaultMapping' => '#/components/schemas/Subscription']),
        ['schema.discriminator-changed!'], ['schema.discriminator-changed!'],
    ],
    // A value that is no Discriminator Object holds no members, so what the other side has reads as gone.
    'a discriminator nothing can read' => [
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice']),
        ['oneOf' => [['$ref' => '#/components/schemas/Invoice']], 'discriminator' => 'type'],
        ['schema.discriminator-changed!', 'schema.discriminator-narrowed!', 'schema.one-of-branch-removed!'],
        ['schema.discriminator-changed!', 'schema.discriminator-narrowed!', 'schema.one-of-branch-removed!'],
    ],
    // A schema compared with itself says nothing, so every row above is the edit and not the probe.
    'a discriminator that did not move' => [
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice']),
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice']),
        [], [],
    ],
]);

it('names the member of a discriminator that moved, and where', function (): void {
    // The field and the path, because "something under the discriminator changed" is not a finding a
    // reviewer can act on — the tag value that was repointed is.
    $changes = (new SchemaComparator)->compare(
        readingDiscriminated(['invoice' => '#/components/schemas/Invoice']),
        readingDiscriminated(['invoice' => '#/components/schemas/Receipt']),
        'S',
        'sch:v1:0000000000000000',
        false,
    );

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->path)->toBe('S.discriminator.mapping.invoice')
        ->and($changes[0]->fields[0]->field)->toBe('mapping.invoice')
        ->and($changes[0]->fields[0]->old)->toBe('#/components/schemas/Invoice')
        ->and($changes[0]->fields[0]->new)->toBe('#/components/schemas/Receipt');
});

it('classifies every nullability edit in both directions', function (array $old, array $new, array $onRequest, array $onResponse): void {
    expect(readingChanges($old, $new, request: true))->toBe($onRequest, 'request')
        ->and(readingChanges($old, $new, request: false))->toBe($onResponse, 'response');
})->with([
    // The server stops accepting a null a writer is still sending.
    'a null withdrawn' => [
        ['type' => 'string', 'nullable' => true],
        ['type' => 'string'],
        ['schema.nullable-narrowed!'], ['schema.nullable-narrowed!'],
    ],
    'a null withdrawn by writing the keyword out' => [
        ['type' => 'string', 'nullable' => true],
        ['type' => 'string', 'nullable' => false],
        ['schema.nullable-narrowed!'], ['schema.nullable-narrowed!'],
    ],
    // A response can now carry a null no existing reader has a case for.
    'a null admitted' => [
        ['type' => 'string'],
        ['type' => 'string', 'nullable' => true],
        ['schema.nullable-widened'], ['schema.nullable-widened!'],
    ],
    // Absent is not nullable, so the keyword written out at what its absence already meant moves nothing.
    'the default written out' => [
        ['type' => 'string'],
        ['type' => 'string', 'nullable' => false],
        [], [],
    ],
    // The 3.0 and 2020-12 spellings are one statement, so migrating between them is not a narrowing —
    // which is what a reading of the keyword alone gets wrong, and it gets it wrong at the gate.
    'the same statement in the other dialect' => [
        ['type' => 'string', 'nullable' => true],
        ['type' => ['string', 'null']],
        ['schema.type-widened'], ['schema.type-widened'],
    ],
    'the same statement in the other dialect, written out' => [
        ['type' => ['string', 'null']],
        ['type' => ['string', 'null'], 'nullable' => true],
        [], [],
    ],
    // A value nothing can read as a boolean is not a null admitted or withdrawn.
    'a nullable nothing can read' => [
        ['type' => 'string', 'nullable' => true],
        ['type' => 'string', 'nullable' => 'yes'],
        ['schema.nullable-changed!'], ['schema.nullable-changed!'],
    ],
    'the same unreadable value on both sides' => [
        ['type' => 'string', 'nullable' => 'yes'],
        ['type' => 'string', 'nullable' => 'yes'],
        [], [],
    ],
]);

it('classifies an identity and a dialect edit in both directions', function (array $old, array $new, array $onRequest, array $onResponse): void {
    expect(readingChanges($old, $new, request: true))->toBe($onRequest, 'request')
        ->and(readingChanges($old, $new, request: false))->toBe($onResponse, 'response');
})->with([
    // A `$ref` may name either, and this comparison resolves none — so a name changed or gone may leave a
    // pointer naming nothing, the reading a `$defs` member leaving already gets.
    'an $id changed' => [
        ['type' => 'string', '$id' => 'https://forms.test/schemas/a'],
        ['type' => 'string', '$id' => 'https://forms.test/schemas/b'],
        ['schema.identity-changed!'], ['schema.identity-changed!'],
    ],
    'an $anchor removed' => [
        ['type' => 'string', '$anchor' => 'formA'],
        ['type' => 'string'],
        ['schema.identity-changed!'], ['schema.identity-changed!'],
    ],
    // Nothing could have pointed at a name that was not there.
    'an $anchor arriving' => [
        ['type' => 'string'],
        ['type' => 'string', '$anchor' => 'formA'],
        ['schema.identity-changed'], ['schema.identity-changed'],
    ],
    // The dialect every keyword beside it is read in: a comparison spanning a change to it compared two
    // languages, and which way that moved is exactly what cannot be computed.
    'a dialect changed' => [
        ['type' => 'string', '$schema' => 'https://json-schema.org/draft/2020-12/schema'],
        ['type' => 'string', '$schema' => 'http://json-schema.org/draft-07/schema#'],
        ['schema.dialect-changed!'], ['schema.dialect-changed!'],
    ],
    'a dialect declared where none was' => [
        ['type' => 'string'],
        ['type' => 'string', '$schema' => 'http://json-schema.org/draft-07/schema#'],
        ['schema.dialect-changed!'], ['schema.dialect-changed!'],
    ],
]);

it('leaves every keyword with a comparison of its own to that comparison, and reports nothing for the rest', function (): void {
    // The rows that are not this table's own comparison, executed. Three name where they are read — a
    // second reading beside the first would report one edit twice — and five are read by nothing, which
    // is a decision recorded here rather than the gap the five unread keywords used to be.
    expect(readingChanges(['type' => 'string'], ['type' => 'integer'], request: true))->toBe(['schema.type-changed!'])
        ->and(readingChanges(['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B'], request: true))
        ->toBe(['schema.ref-changed'])
        ->and(readingChanges(['type' => 'object', 'required' => []], ['type' => 'object', 'required' => ['a']], request: true))
        ->toBe(['schema.required-added!'])
        ->and(readingChanges(['type' => 'string', 'title' => 'Was'], ['type' => 'string', 'title' => 'Now'], request: true))
        ->toBe(['schema.annotation-changed']);

    // `x-docuccino` carries the identity the diff pairs nodes BY; the other four say something about the
    // value that this diff does not read yet, and say so in the table rather than in a gap.
    $unread = [
        'x-docuccino' => [['id' => 'sch:v1:aaaaaaaaaaaaaaaa'], ['id' => 'sch:v1:bbbbbbbbbbbbbbbb']],
        'default' => ['draft', 'published'],
        'readOnly' => [false, true],
        'writeOnly' => [false, true],
        'deprecated' => [false, true],
    ];

    foreach ($unread as $keyword => [$before, $after]) {
        expect(SchemaReading::rule($keyword))->toBe(ReadingKind::Unread, $keyword.' reading')
            ->and(readingChanges(['type' => 'string', $keyword => $before], ['type' => 'string', $keyword => $after], request: true))
            ->toBe([], $keyword.' is recorded as read by nothing and reported something');
    }
});

it('carries a reading up from every subschema position it sits under', function (): void {
    // The keyword is read wherever a schema is, not only at the top of one — and under `not` the direction
    // inverts, so the child's verdict cannot carry up and the conservative one says so.
    expect(readingChanges(
        ['type' => 'object', 'properties' => ['payload' => readingDiscriminated(['invoice' => '#/components/schemas/Invoice'])]],
        ['type' => 'object', 'properties' => ['payload' => readingDiscriminated(['invoice' => '#/components/schemas/Receipt'])]],
        request: true,
    ))->toBe(['schema.discriminator-changed!'])
        ->and(readingChanges(
            ['allOf' => [['type' => 'string', 'nullable' => true]]],
            ['allOf' => [['type' => 'string']]],
            request: true,
        ))->toBe(['schema.nullable-narrowed!'])
        ->and(readingChanges(
            ['not' => ['type' => 'string']],
            ['not' => ['type' => 'string', 'nullable' => true]],
            request: false,
        ))->toBe(['schema.nullable-widened!']);
});

it('names a repointed discriminator mapping through the path a diff actually runs', function (): void {
    // The end-to-end claim: this is the edit `--enforce` used to pass as safe. A polymorphic response
    // routes the `invoice` tag to another type, every generated client deserialises it as that type, and
    // the gate says so at a path a reviewer can find.
    $document = static fn (string $target): UirDocument => UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/things' => ['get' => [
            'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
            'operationId' => 'things.index',
            'responses' => ['200' => [
                'x-docuccino' => ['id' => 'res:v1:bbbbbbbbbbbbbbbb'],
                'description' => 'ok',
                'content' => ['application/json' => ['schema' => [
                    'oneOf' => [['$ref' => '#/components/schemas/Invoice'], ['$ref' => '#/components/schemas/Receipt']],
                    'discriminator' => ['propertyName' => 'type', 'mapping' => ['invoice' => $target]],
                ]]],
            ]],
        ]]],
        'components' => ['schemas' => [
            'Invoice' => ['x-docuccino' => ['id' => 'sch:v1:cccccccccccccccc'], 'type' => 'object'],
            'Receipt' => ['x-docuccino' => ['id' => 'sch:v1:dddddddddddddddd'], 'type' => 'object'],
        ]],
    ]);

    $changeset = (new DocumentDiffer)->diff($document('#/components/schemas/Invoice'), $document('#/components/schemas/Receipt'));
    $found = array_values(array_filter(
        $changeset->changes,
        static fn ($change): bool => $change->code === 'schema.discriminator-changed',
    ));

    expect($changeset->isBreaking())->toBeTrue()
        ->and($found)->toHaveCount(1)
        ->and($found[0]->path)->toBe('GET /things responses 200 application/json schema.discriminator.mapping.invoice')
        ->and($found[0]->fields[0]->field)->toBe('mapping.invoice')
        ->and($found[0]->fields[0]->old)->toBe('#/components/schemas/Invoice')
        ->and($found[0]->fields[0]->new)->toBe('#/components/schemas/Receipt');
});

it('reads a discriminator mapping as the map it is', function (): void {
    // The pairing rule, at the unit that owns it: entries pair by KEY, so a reorder moves nothing while
    // the same tag pointing somewhere else is a change nothing can order.
    expect(SchemaReading::discriminatorMoves(
        ['propertyName' => 'type', 'mapping' => ['a' => 'X', 'b' => 'Y']],
        ['propertyName' => 'type', 'mapping' => ['b' => 'Y', 'a' => 'X']],
    ))->toBe([])
        ->and(array_map(
            static fn (array $move): RefinementMove => $move['move'],
            SchemaReading::discriminatorMoves(
                ['propertyName' => 'type', 'mapping' => ['a' => 'X', 'b' => 'Y']],
                ['propertyName' => 'kind', 'mapping' => ['a' => 'Z', 'c' => 'W']],
            ),
        ))->toBe([
            'mapping.a' => RefinementMove::Incomparable,
            'mapping.b' => RefinementMove::Narrowed,
            'mapping.c' => RefinementMove::Widened,
            'propertyName' => RefinementMove::Incomparable,
        ]);
});

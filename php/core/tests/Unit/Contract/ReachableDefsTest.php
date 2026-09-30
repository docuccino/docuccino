<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ReachableDefs;
use Opis\JsonSchema\Parsers\Drafts\Draft06;
use Opis\JsonSchema\Parsers\Drafts\Draft07;
use Opis\JsonSchema\Parsers\Drafts\Draft201909;
use Opis\JsonSchema\Parsers\Drafts\Draft202012;
use Opis\JsonSchema\Parsers\Keywords\RefKeywordParser;

/*
 * What a check may leave behind is whatever no `$ref` can reach, so each spelling below is what the
 * validator itself resolves the reference to: a pointer segment is percent-decoded and then `~1`/`~0`
 * unescaped, which is why `%24defs` is the `$defs` member and `A~1B` is the component `A/B`.
 */
dataset('pointer references', [
    'a member of $defs' => ['#/$defs/A', ['A']],
    'a pointer into one' => ['#/$defs/A/properties/x', ['A']],
    'an escaped slash' => ['#/$defs/A~1B', ['A/B']],
    'an escaped tilde' => ['#/$defs/A~0B', ['A~B']],
    'a percent-encoded name' => ['#/$defs/A%20B', ['A B']],
    'a percent-encoded $defs' => ['#/%24defs/A', ['A']],
    'the root' => ['#', []],
    'the root as a pointer' => ['#/', []],
    'the subject itself' => ['#/properties/x', []],
]);

it('reads a pointer reference as the $defs member it resolves to', function (string $ref, array $names): void {
    expect(ReachableDefs::of((object) ['$ref' => $ref]))->toBe($names);
})->with('pointer references');

dataset('references it cannot follow', [
    '$defs itself' => ['#/$defs'],
    'an anchor' => ['#leaf'],
    'another document' => ['other.json#/$defs/A'],
    'an absolute URI' => ['https://example.com/schemas/a.json'],
    'a relative JSON pointer' => ['0/properties/a'],
    'a template' => ['#/$defs/{name}'],
    'nothing at all' => [''],
]);

it('gives up on every reference that is not a plain pointer from the root', function (string $ref): void {
    expect(ReachableDefs::of((object) ['$ref' => $ref]))->toBeNull();
})->with('references it cannot follow');

it('gives up on a $ref that is not a string', function (): void {
    expect(ReachableDefs::of((object) ['$ref' => 42]))->toBeNull();
});

/*
 * The members that name a schema without a pointer, read off the validator's own grammar rather than
 * listed: each draft's `$ref` parser declares the dynamic and recursive variations it resolves, and the
 * parser reads `$id`, `$anchor` and `$schema` directly. A variation a new release adds would otherwise
 * be one the reachability read steps straight past.
 */
dataset('addressing members', function (): array {
    $members = ['$id', '$anchor', '$schema'];

    foreach ([Draft06::class, Draft07::class, Draft201909::class, Draft202012::class] as $draft) {
        $parser = (new ReflectionMethod($draft, 'getRefKeywordParser'))->invoke(new $draft);
        assert($parser instanceof RefKeywordParser);

        foreach ((new ReflectionProperty(RefKeywordParser::class, 'variations'))->getValue($parser) ?? [] as $variation) {
            $members[] = $variation['ref'];
            $members[] = $variation['anchor'];
        }
    }

    $members = array_values(array_unique($members));

    // Four variation members exist today; reading none would mean the grammar moved, not that it shrank.
    expect(count($members))->toBeGreaterThanOrEqual(7);

    return array_combine($members, array_map(static fn (string $m): array => [$m], $members));
});

it('gives up on a schema carrying any member that names a schema another way', function (string $member): void {
    $schema = (object) [
        'type' => 'object',
        'properties' => (object) ['nested' => (object) [$member => 'x', 'type' => 'string']],
    ];

    expect(ReachableDefs::of($schema))->toBeNull();
})->with('addressing members');

it('collects every reference however deep, lists and objects alike', function (): void {
    $schema = (object) [
        'oneOf' => [(object) ['$ref' => '#/$defs/A'], (object) ['items' => (object) ['$ref' => '#/$defs/B']]],
        'properties' => (object) ['c' => (object) ['$ref' => '#/$defs/C']],
    ];

    expect(ReachableDefs::of($schema))->toEqualCanonicalizing(['A', 'B', 'C']);
});

it('follows every def a reached def reaches, and nothing else', function (): void {
    $reaches = ['A' => ['B'], 'B' => ['C', 'A'], 'C' => [], 'D' => ['A']];

    expect(ReachableDefs::closure(['A'], $reaches))->toBe(['A' => true, 'B' => true, 'C' => true])
        ->and(ReachableDefs::closure([], $reaches))->toBe([]);
});

it('skips a name no def holds, since that reference resolves to nothing whatever travels', function (): void {
    expect(ReachableDefs::closure(['Missing', 'C'], ['C' => []]))->toBe(['C' => true]);
});

it('gives up on the whole closure the moment a reached def cannot be read', function (): void {
    $reaches = ['A' => ['B'], 'B' => null, 'C' => []];

    expect(ReachableDefs::closure(['A'], $reaches))->toBeNull()
        ->and(ReachableDefs::closure(['C'], $reaches))->toBe(['C' => true])
        ->and(ReachableDefs::closure(null, $reaches))->toBeNull();
});

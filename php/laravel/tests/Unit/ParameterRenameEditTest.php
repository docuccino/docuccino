<?php

declare(strict_types=1);

use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Laravel\Versioning\ParameterRenameEdit;
use Docuccino\Laravel\Versioning\VerbOutcome;

/*
 * The two nodes a real versioned build never hands `#[RenamedParameter]`, asked of the verb directly.
 *
 * Every operation of a versioned document is given the version header, so `parameters` is always there
 * and every parameter the recovery wrote carries an identity — which means the degradations below are
 * reachable only from a node something else wrote: a webhook, which the header pass leaves alone
 * because a webhook is a request the SERVER makes, or an overlay-written parameter. They are behaviour
 * either way, so they are pinned here rather than left to whichever build meets one first.
 */

/** The verb under test, and the identity generator the transformer would hand it. */
function parameterRename(string $from = 'q', string $to = 'search'): ParameterRenameEdit
{
    return new ParameterRenameEdit('query', $from, $to);
}

it('leaves an operation that declares no parameters alone, and reports nothing found', function (): void {
    $operation = ['x-docuccino' => ['id' => 'op:v1:one'], 'responses' => ['200' => ['description' => 'OK']]];
    $outcome = VerbOutcome::Absent;

    expect(parameterRename()->apply($operation, 'op:v1:one', new IdentityGenerator, $outcome))->toBe($operation)
        ->and($outcome)->toBe(VerbOutcome::Absent);
});

it('renames a parameter that carries no identity without inventing one', function (): void {
    // Nothing to re-mint from and nothing to correct: an id is a fact about where a node came from, and
    // minting one here would claim this parameter was recovered when it was written by hand.
    $operation = ['parameters' => [['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']]]];
    $outcome = VerbOutcome::Absent;

    $edited = parameterRename()->apply($operation, 'op:v1:one', new IdentityGenerator, $outcome);

    expect($outcome)->toBe(VerbOutcome::Applied)
        ->and($edited['parameters'][0])->toBe(['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']])
        ->and($edited['parameters'][0])->not->toHaveKey('x-docuccino');
});

it('skips a parameter written as a $ref, which is shared with every site that references it', function (): void {
    // It states no name here, so there is nothing to rename — and renaming the component it points at
    // would rename it for every other operation too, including ones a scope was written to exclude.
    $operation = ['parameters' => [['$ref' => '#/components/parameters/Search']]];
    $outcome = VerbOutcome::Absent;

    expect(parameterRename()->apply($operation, 'op:v1:one', new IdentityGenerator, $outcome))->toBe($operation)
        ->and($outcome)->toBe(VerbOutcome::Absent);
});

it('re-mints the identity from where the operation stands when the operation carries none', function (): void {
    // The same fallback a forked schema's ids are re-minted against: the id has to stay a function of
    // the thing, and an operation with no identity of its own still has a position.
    $operation = ['parameters' => [[
        'x-docuccino' => ['id' => 'par:v1:whatever'],
        'name' => 'search',
        'in' => 'query',
    ]]];
    $outcome = VerbOutcome::Absent;
    $identity = new IdentityGenerator;

    $edited = parameterRename()->apply($operation, 'paths//api/things/get', $identity, $outcome);

    expect($edited['parameters'][0]['x-docuccino']['id'])
        ->toBe($identity->parameterId('paths//api/things/get', 'query', 'q'));
});

it('raises the strongest outcome it saw rather than overwriting an earlier one', function (): void {
    // One verb is walked across every operation in scope, so an operation that had nothing to rename
    // must not undo the edit another one took.
    $outcome = VerbOutcome::Applied;
    $operation = ['responses' => ['200' => ['description' => 'OK']]];

    parameterRename()->apply($operation, 'op:v1:one', new IdentityGenerator, $outcome);

    expect($outcome)->toBe(VerbOutcome::Applied);
});

<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Identity\IdentityGenerator;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * `#[RenamedParameter]`, against real builds of the workbench.
 *
 * A parameter is the one thing in the vocabulary that is NOT a schema property, and nearly everything
 * below follows from that. It is flattened onto `operation.parameters[]` under an identity that is a
 * function of the operation, the location and the NAME — so there is no component to resolve, nothing
 * shared to fork, and the name being moved is part of what identifies the thing being moved.
 *
 * Two consequences the suite pins rather than assumes. The identity has to be RE-MINTED, or the
 * document publishes a parameter whose id claims a name it does not have. And `#[AppliesTo]` is a plain
 * filter here: a parameter belongs to one operation already, so the "scope covers every operation →
 * rename the shared shape in place" branch the schema verbs run has nothing to be about.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindVersionedRequestEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-search', [VersionedFormController::class, 'search']);
    $router->post('api/versioned-forms', [VersionedFormController::class, 'store']);
});

it('publishes a query parameter under the name older versions took', function (): void {
    $parameters = versionedSearchParameters('tests/Fixtures/Versioning/RenamedParam');

    // In place, so the parameter keeps its position beside the ones the rename does not touch.
    expect(array_column($parameters, 'name'))->toBe(['q', 'trace_id']);
});

it('reads `in:` in any case, the way every other declaration that takes one does', function (): void {
    expect(array_column(versionedSearchParameters('tests/Fixtures/Versioning/RenamedParamMiscased'), 'name'))
        ->toBe(['q', 'trace_id']);
});

/*
 * The re-mint, stated from the rule rather than from the code that performs it: a parameter's identity
 * IS a function of its operation, its location and its name, so the id standing on the renamed node has
 * to be the one that name mints — and the parameter beside it, which nothing renamed, has to be
 * untouched. A guard that compared the two builds' ids and only asked them to DIFFER would pass on an
 * id invented out of nothing.
 */
it('re-mints the renamed parameter identity, and moves no other', function (): void {
    $before = versionedSearchParameters(null);
    $after = versionedSearchParameters('tests/Fixtures/Versioning/RenamedParam');

    $operation = generateDocument(key: 'v')->document->toArray()['paths']['/api/versioned-search']['get'];
    $operationId = $operation['x-docuccino']['id'];
    $identity = new IdentityGenerator;

    expect($after[0]['x-docuccino']['id'])->toBe($identity->parameterId($operationId, 'query', 'q'))
        ->and($after[0]['x-docuccino']['id'])->not->toBe($before[0]['x-docuccino']['id'])
        // And the head build's own id really was the one today's name mints, so the line above is a
        // statement about the mint rather than about two arbitrary hashes.
        ->and($before[0]['x-docuccino']['id'])->toBe($identity->parameterId($operationId, 'query', 'search'))
        ->and($after[1]['x-docuccino']['id'])->toBe($before[1]['x-docuccino']['id']);
});

/*
 * And the reason the re-mint matters beyond tidiness: the identity is what everything downstream
 * resolves a node by, so a renamed parameter left carrying its old id would be addressable under the
 * name it no longer has and unaddressable under the one it does.
 */
it('keeps the renamed parameter addressable by the identity its own name mints', function (): void {
    $after = versionedSearchParameters('tests/Fixtures/Versioning/RenamedParam');
    $identity = new IdentityGenerator;
    $operationId = generateDocument(key: 'v')->document->toArray()['paths']['/api/versioned-search']['get']['x-docuccino']['id'];

    $ids = array_map(static fn (array $parameter): string => $parameter['x-docuccino']['id'], $after);

    expect($ids)->not->toContain($identity->parameterId($operationId, 'query', 'search'))
        ->and($ids)->toContain($identity->parameterId($operationId, 'query', 'q'));
});

/*
 * The parameter's own example, which is the position a schema-field rename has no analogue for — and
 * the finding this assertion records: it holds the parameter's VALUE, so a rename of the parameter's
 * NAME moves nothing inside it. An example rewrite here would be a defect, not a feature.
 */
it('leaves the renamed parameter carrying exactly the example and schema it had', function (): void {
    $before = versionedSearchParameters(null);
    $after = versionedSearchParameters('tests/Fixtures/Versioning/RenamedParam');

    expect($after[0]['schema'])->toBe($before[0]['schema'])
        ->and($after[0]['schema']['example'])->toBe('example')
        ->and($after[0]['required'])->toBe($before[0]['required']);
});

it('says nothing when a parameter rename applies', function (): void {
    expect(versioningDiagnostics('tests/Fixtures/Versioning/RenamedParam', route: 'api/versioned-*'))->toBe([]);
});

/*
 * `#[AppliesTo]` as a filter and nothing more. The scope names the one operation that declares the
 * parameter, which on the schema side would be the "covers all → rename in place" branch; here there is
 * no branch to take, because the parameter was only ever on that operation.
 */
it('narrows a parameter rename to the operations the scope names', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RenamedParamScoped', route: 'api/versioned-*');

    expect($diagnostics)->toBe([])
        ->and(array_column(versionedSearchParameters('tests/Fixtures/Versioning/RenamedParamScoped'), 'name'))
        ->toBe(['q', 'trace_id']);
});

/*
 * A scope matching nothing is indistinguishable from a change that was never declared — a route renamed
 * months later silently stops the change applying, with nothing edited — so it is said. And its wording
 * is the parameter one: there is no schema to say the document publishes it for.
 */
it('says a scoped parameter rename names no operation this document publishes', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RenamedParamScopedNowhere', route: 'api/versioned-*');

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))
        ->toBe(['versioning.scope-matches-nothing'])
        ->and($diagnostics[0]->message)->toContain('scoped to "GET /api/forms-that-moved"')
        ->toContain('names no operation this document publishes')
        ->toContain('the query parameter "search"');

    // And nothing was renamed: a scope that reaches nothing never widens into a document-wide rename.
    expect(array_column(versionedSearchParameters('tests/Fixtures/Versioning/RenamedParamScopedNowhere'), 'name'))
        ->toBe(['search', 'trace_id']);
});

it('says a parameter rename has rotted when no operation declares what it names', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RenamedParamMissing', route: 'api/versioned-*');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.change-target-missing')
        ->and($diagnostics[0]->message)->toContain('renames the query parameter "sort"')
        ->toContain('which no operation this document publishes declares')
        ->and($diagnostics[0]->help)->toContain('name the parameter as it is spelled today');
});

it('refuses to rename a parameter onto a name the operation already declares', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RenamedParamTaken', route: 'api/versioned-*');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.change-invalid')
        ->and($diagnostics[0]->message)->toContain('already declares a query parameter called "trace_id"')
        ->toContain('collapse two parameters into one');

    // Two parameters of one name in one location is a document no client can read, so nothing moved.
    expect(array_column(versionedSearchParameters('tests/Fixtures/Versioning/RenamedParamTaken'), 'name'))
        ->toBe(['search', 'trace_id']);
});

/*
 * `in:` is a closed set, and a value outside it names nothing to look for. It cannot be widened to "any
 * location" either: two operations can carry `page` in the query and in the path, so a rename that
 * guessed would move a parameter the author never named.
 */
it('refuses an `in:` that names no parameter location, quoting the ones that do', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RenamedParamUnknownIn', route: 'api/versioned-*');

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe(['versioning.change-invalid'])
        ->and($diagnostics[0]->message)->toContain('`in: "body"`, which names no parameter location')
        ->and($diagnostics[0]->help)->toContain('`cookie`, `header`, `path`, `query`')
        ->toContain('spelled in any case');
});

it('refuses a parameter rename with an empty end, or one onto itself', function (string $dir, string $problem): void {
    $diagnostics = versioningDiagnostics($dir, route: 'api/versioned-*');

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe(['versioning.change-invalid'])
        ->and($diagnostics[0]->message)->toContain('#[RenamedParameter] declarations '.$problem);
})->with([
    'an empty end' => ['tests/Fixtures/Versioning/EmptyRenamedParameter', 'leaves `from:` or `to:` empty'],
    'a rename onto itself' => ['tests/Fixtures/Versioning/RenamedParamSelf', 'renames "search" to itself'],
]);

it('leaves a parameter rename that shipped at or before this version alone', function (): void {
    expect(versioningDiagnostics('tests/Fixtures/Versioning/RenamedParam', '2026-09-01', 'api/versioned-*'))->toBe([]);

    $parameters = generateDocument(key: 'v')->document->toArray()['paths']['/api/versioned-search']['get']['parameters'];

    expect(array_column($parameters, 'name'))->toBe(['search', 'trace_id']);
});

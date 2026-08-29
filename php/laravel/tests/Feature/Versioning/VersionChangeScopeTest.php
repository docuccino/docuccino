<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * `#[AppliesTo]`, and the fork it forces.
 *
 * Two operations publish one `FormData` component. A change scoped to both must come out exactly as an
 * unscoped one does; a change scoped to one of them cannot, because in that version those operations
 * really do have a different type, and the fork is what says so.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);
    $router->get('api/versioned-forms/archived', [VersionedFormController::class, 'archived']);
});

/**
 * @return array<string, mixed>
 */
function scopedVersionDocument(?string $dir): array
{
    return [
        'info' => ['title' => 'Forms API', 'version' => '2026-06-01'],
        'routes' => ['include' => ['api/versioned-forms*']],
        'error_responses' => 'none',
        'api_version' => $dir === null ? [] : ['changes' => ['dir' => $dir]],
    ];
}

it('renames the shared component in place when the scope covers every operation that publishes it', function (): void {
    config()->set('docuccino.documents', ['v' => scopedVersionDocument('tests/Fixtures/Versioning/ScopedAll')]);
    $scoped = generateDocument(key: 'v')->document->toArray();

    config()->set('docuccino.documents', ['v' => scopedVersionDocument('workbench/app/Api/Versions')]);
    $unscoped = generateDocument(key: 'v')->document->toArray();

    // Both operations keep the shared `$ref`, and the component itself carries the older name.
    expect(array_keys($scoped['components']['schemas']['FormData']['properties']))->toBe(['id', 'name', 'publishedAt'])
        ->and($scoped['components']['schemas']['FormData']['required'])->toBe(['id', 'name'])
        ->and($scoped['paths']['/api/versioned-forms']['get']['responses']['200']['content']['application/json']['schema']['items'])
        ->toBe(['$ref' => '#/components/schemas/FormData'])
        ->and($scoped['paths']['/api/versioned-forms/archived']['get']['responses']['200']['content']['application/json']['schema']['items'])
        ->toBe(['$ref' => '#/components/schemas/FormData']);

    // And the whole point of branch 2: covering every operation is not a different document from
    // scoping none of them. Everything but the document's own hashes, which differ because the two runs
    // read their changes out of different directories and so are configured differently.
    unset($scoped['x-docuccino']['document'], $unscoped['x-docuccino']['document']);

    expect(json_encode($scoped))->toBe(json_encode($unscoped));
});

it('inlines the older shape at the operations in scope and leaves the component to the rest', function (): void {
    config()->set('docuccino.documents', ['v' => scopedVersionDocument('tests/Fixtures/Versioning/Scoped')]);

    $document = generateDocument(key: 'v')->document->toArray();

    $inScope = $document['paths']['/api/versioned-forms']['get']['responses']['200']['content']['application/json']['schema']['items'];
    $outOfScope = $document['paths']['/api/versioned-forms/archived']['get']['responses']['200']['content']['application/json']['schema']['items'];

    // The in-scope operation carries the older shape as a schema of its own, with no `$ref` left to the
    // component it was forked from — a copy still pointing at the component would BE the component.
    expect($inScope)->not->toHaveKey('$ref')
        ->and(array_keys($inScope['properties']))->toBe(['id', 'name', 'publishedAt'])
        ->and($inScope['required'])->toBe(['id', 'name']);

    // The out-of-scope one is untouched, and so is the component it shares with everybody else.
    expect($outOfScope)->toBe(['$ref' => '#/components/schemas/FormData'])
        ->and(array_keys($document['components']['schemas']['FormData']['properties']))->toBe(['id', 'title', 'publishedAt'])
        ->and($document['components']['schemas']['FormData']['required'])->toBe(['id', 'title']);
});

/*
 * The reason the fork inlines. A minted `FormDataV2` would be a published type name that appeared and
 * disappeared depending on how many operations happened to share a body, so adding an unrelated endpoint
 * would rename a type in somebody's generated client. An inline schema registers no name at all.
 */
it('mints no new component name for the operations it forks', function (): void {
    config()->set('docuccino.documents', ['v' => scopedVersionDocument('tests/Fixtures/Versioning/Scoped')]);

    $document = generateDocument(key: 'v')->document->toArray();

    expect(array_keys($document['components']['schemas']))->toBe(['FormData']);
});

it('emits a valid document once it has forked one', function (): void {
    config()->set('docuccino.documents', ['v' => scopedVersionDocument('tests/Fixtures/Versioning/Scoped')]);

    $result = generateDocument(key: 'v');

    $invalid = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'document.schema-invalid',
    ));

    expect($invalid)->toBe([])
        ->and((new UirEmitter)->emit($result->document))->toContain('"name"');
});

it('says nothing about scope while forking a document that asked for one', function (): void {
    config()->set('docuccino.documents', ['v' => scopedVersionDocument('tests/Fixtures/Versioning/Scoped')]);

    $codes = array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code,
        generateDocument(key: 'v')->diagnostics,
    );

    expect(array_values(array_filter($codes, static fn (string $code): bool => str_starts_with($code, 'versioning.'))))->toBe([]);
});

/*
 * A selector that matches nothing is indistinguishable from a change nobody declared: the route it
 * named was renamed months later and the change silently stopped applying. So it is said out loud.
 */
it('reports a scope that names no operation publishing the schema, and applies nothing', function (): void {
    config()->set('docuccino.documents', ['v' => scopedVersionDocument('tests/Fixtures/Versioning/ScopedNowhere')]);

    $result = generateDocument(key: 'v');
    $document = $result->document->toArray();

    $versioning = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $diagnostic): bool => str_starts_with($diagnostic->code, 'versioning.'),
    ));

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $versioning))
        // The blank selector is refused where every other malformed declaration is; the live one that
        // matches nothing is a fact about THIS document's operations, which is a different report.
        ->toBe(['versioning.change-invalid', 'versioning.scope-matches-nothing'])
        ->and($versioning[1]->message)->toContain('GET /api/forms-as-they-were-called-then')
        ->and($versioning[1]->message)->toContain('Workbench\\App\\Data\\FormData')
        ->and($versioning[1]->help)->toContain('operationId');

    // And nothing was renamed: a scope that matched nothing must not quietly rename everything.
    expect(array_keys($document['components']['schemas']['FormData']['properties']))->toBe(['id', 'title', 'publishedAt']);
});

it('names an operation by its operationId as readily as by its signature', function (): void {
    config()->set('docuccino.documents', ['v' => scopedVersionDocument('tests/Fixtures/Versioning/ScopedById')]);

    $document = generateDocument(key: 'v')->document->toArray();
    $items = static fn (string $path): array => $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema']['items'];

    // The mirror image of the signature case, which is what makes it a test of the selector rather than
    // of the route table: an `operationId` selector forks the OTHER operation.
    expect($document['paths']['/api/versioned-forms/archived']['get']['operationId'])->toBe('listArchivedForms')
        ->and($items('/api/versioned-forms/archived'))->not->toHaveKey('$ref')
        ->and(array_keys($items('/api/versioned-forms/archived')['properties']))->toBe(['id', 'name', 'publishedAt'])
        ->and($items('/api/versioned-forms'))->toBe(['$ref' => '#/components/schemas/FormData']);
});

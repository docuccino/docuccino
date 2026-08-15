<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;

it('dedupes a class by its schemaId across references', function (): void {
    $registry = new ComponentRegistry;

    $first = $registry->reference('FormData', ['type' => 'object'], 'App\\Data\\FormData');
    $second = $registry->reference('FormData', ['type' => 'object', 'title' => 'ignored'], 'App\\Data\\FormData');

    expect($first)->toBe(['$ref' => '#/components/schemas/FormData'])
        ->and($second)->toBe(['$ref' => '#/components/schemas/FormData'])
        ->and($registry->schemas())->toHaveCount(1)
        ->and($registry->diagnostics())->toBe([]);
});

it('dedupes structurally-equal anonymous schemas under one name', function (): void {
    $registry = new ComponentRegistry;

    $registry->registerSchema('Thing', ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]);
    $name = $registry->registerSchema('Thing', ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]);

    expect($name)->toBe('Thing')
        ->and($registry->schemas())->toHaveCount(1);
});

it('suffixes a genuine name collision and warns', function (): void {
    $registry = new ComponentRegistry;

    $registry->registerSchema('Thing', ['type' => 'object']);
    $name = $registry->registerSchema('Thing', ['type' => 'string']);

    expect($name)->toBe('Thing_2')
        ->and($registry->schemas())->toHaveKeys(['Thing', 'Thing_2']);

    $diagnostics = $registry->diagnostics();
    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->code)->toBe('components.name-collision');
});

it('names both claimants in the collision message, on either registration path', function (string $path): void {
    // The short name in the message identifies neither class, so an app with two `SSOConnectionData`
    // classes can only act on the warning if it carries the FQCNs. Both paths that suffix must say so:
    // registering a body, and reserving a name up front for a self-referential class.
    $registry = new ComponentRegistry;

    if ($path === 'register') {
        $registry->registerSchema('Node', ['type' => 'object'], 'App\\A\\Node');
        $registry->registerSchema('Node', ['type' => 'string'], 'App\\B\\Node');
    } else {
        $registry->reserveSchemaName('Node', 'App\\A\\Node');
        $registry->reserveSchemaName('Node', 'App\\B\\Node');
    }

    expect($registry->diagnostics()[0]->message)
        ->toContain('"Node"')
        ->toContain('App\\A\\Node')
        ->toContain('App\\B\\Node')
        ->toContain('"Node_2"')
        ->and($registry->diagnostics()[0]->help)->toContain('#[SchemaName]');
})->with(['register', 'reserve']);

it('degrades the collision message when a claimant has no identity to name', function (): void {
    // An inline shape registered under a name has no FQCN — the message says so rather than printing
    // an empty parenthesis, and still names the half it does know.
    $registry = new ComponentRegistry;

    $registry->registerSchema('Node', ['type' => 'object']);
    $registry->registerSchema('Node', ['type' => 'string'], 'App\\B\\Node');

    expect($registry->diagnostics()[0]->message)
        ->toContain('an unidentified schema and App\\B\\Node');
});

it('hands a snapshot-scoped slice of diagnostics to its caller and keeps none back', function (): void {
    // The seam that lets a route's fragment carry its own component diagnostics: what it takes is
    // exactly what was added since the snapshot, and the registry keeps none of it, so the assembler
    // draining the registry afterwards cannot report the same warning twice.
    $registry = new ComponentRegistry;
    $registry->registerSchema('Thing', ['type' => 'object']);
    $registry->registerSchema('Thing', ['type' => 'string']);

    $snapshot = $registry->snapshot();
    $registry->registerSchema('Thing', ['type' => 'integer']);

    $taken = $registry->takeDiagnosticsSince($snapshot);

    expect($taken)->toHaveCount(1)
        ->and($taken[0]->message)->toContain('"Thing_3"')
        ->and($registry->diagnostics())->toHaveCount(1)
        ->and($registry->diagnostics()[0]->message)->toContain('"Thing_2"');
});

it('takes nothing when a route registered no components at all', function (): void {
    // The overwhelmingly common case — the slice has to be empty, not the whole list.
    $registry = new ComponentRegistry;
    $registry->registerSchema('Thing', ['type' => 'object']);
    $registry->registerSchema('Thing', ['type' => 'string']);

    expect($registry->takeDiagnosticsSince($registry->snapshot()))->toBe([])
        ->and($registry->diagnostics())->toHaveCount(1);
});

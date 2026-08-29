<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\UirEmitter;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;
use Workbench\App\Http\Middleware\DowngradeToPinnedApiVersion;

/**
 * One route, two documents: the version the code is, and the version before the rename shipped.
 *
 * No golden is committed for either. A live version legitimately changes — a non-breaking correction
 * is backported to every version still being served — so a byte-lock would fail on every honest fix.
 * The facts are pinned instead.
 *
 * The routes and the `documents` bag are declared here rather than in `TestCase::defineRoutes()` and
 * the shipped config: that route set is enumerated verbatim in six byte-locked goldens, and this
 * suite must move none of them.
 */
beforeEach(function (): void {
    // The workbench is not under the testbench skeleton's base path, so the change classes are only
    // reachable once the base path is the adapter package.
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->middleware(DowngradeToPinnedApiVersion::class)
        ->get('api/versioned-forms', [VersionedFormController::class, 'index']);

    config()->set('docuccino.documents', versionedFormDocuments());
});

it('publishes the field the code publishes today in the version the rename shipped in', function (): void {
    $schema = generateDocument(key: 'v2026-09-01')->document->toArray()['components']['schemas']['FormData'];

    expect(array_keys($schema['properties']))->toBe(['id', 'title', 'publishedAt'])
        ->and($schema['required'])->toBe(['id', 'title']);
});

it('publishes the former field name in a version older than the change', function (): void {
    $schema = generateDocument(key: 'v2026-06-01')->document->toArray()['components']['schemas']['FormData'];

    expect(array_keys($schema['properties']))->toBe(['id', 'name', 'publishedAt'])
        ->and($schema['properties']['name'])->toBe(['type' => 'string'])
        ->and($schema['properties'])->not->toHaveKey('title');
});

/*
 * Load-bearing, and the reason the acceptance proof works at all: a `required` still naming today's
 * field would accept a body carrying the new name and reject one carrying the old, which is the exact
 * disagreement the per-version contract test exists to catch.
 */
it('rewrites the required list with the properties it names', function (): void {
    $schema = generateDocument(key: 'v2026-06-01')->document->toArray()['components']['schemas']['FormData'];

    expect($schema['required'])->toBe(['id', 'name'])
        ->and($schema['required'])->not->toContain('title');
});

it('derives info.version from the version the document declares, whatever info says', function (): void {
    // Both documents configure `info.version` as something else on purpose: the version is stated once,
    // under `api_version`, so the two halves of the document cannot drift apart.
    $head = generateDocument(key: 'v2026-09-01')->document->toArray();
    $older = generateDocument(key: 'v2026-06-01')->document->toArray();

    expect($head['info']['version'])->toBe('2026-09-01')
        ->and($older['info']['version'])->toBe('2026-06-01');
});

it('declares the version header on every operation, enumerating every configured version', function (string $key, string $version): void {
    $document = generateDocument(key: $key)->document->toArray();
    $parameters = $document['paths']['/api/versioned-forms']['get']['parameters'];

    $header = array_values(array_filter(
        $parameters,
        static fn (array $parameter): bool => $parameter['name'] === 'X-Api-Version',
    ))[0] ?? null;

    expect($header)->not->toBeNull()
        ->and($header['in'])->toBe('header')
        ->and($header['required'])->toBeFalse()
        ->and($header['schema']['enum'])->toBe(['2026-06-01', '2026-09-01'])
        ->and($header['schema']['default'])->toBe($version)
        // A date is not an identifier, so the enum carries the member names that make it one — the same
        // decoration every other published enum gets.
        ->and($header['schema']['x-enum-varnames'])->toBe(['_20260601', '_20260901'])
        ->and($header['schema']['x-enumNames'])->toBe(['_20260601', '_20260901'])
        // And the change's own sentence, keyed to the version it shipped in.
        ->and($header['schema']['x-enum-descriptions'])->toBe(['', 'A form publishes `title` where it published `name`.']);
})->with([
    'the head version' => ['v2026-09-01', '2026-09-01'],
    'the older version' => ['v2026-06-01', '2026-06-01'],
]);

it('never publishes an enum that leaves out the version the document defaults to', function (): void {
    // A build whose document is not in the `documents` bag — a programmatic one, a key mid-rename —
    // would otherwise publish a `default` its own `enum` refuses, which marks a working request invalid.
    config()->set('docuccino.documents', ['v2026-09-01' => versionedFormDocuments()['v2026-09-01']]);

    $document = generateDocument(static function (array $raw): array {
        $raw['api_version']['version'] = '2027-03-01';

        return $raw;
    }, 'v2026-09-01')->document->toArray();

    $schema = $document['paths']['/api/versioned-forms']['get']['parameters'][0]['schema'];

    expect($schema['enum'])->toBe(['2026-09-01', '2027-03-01'])
        ->and($schema['enum'])->toContain($schema['default']);
});

it('mints the header parameter an identity of its own per operation', function (): void {
    $head = generateDocument(key: 'v2026-09-01')->document->toArray();
    $older = generateDocument(key: 'v2026-06-01')->document->toArray();

    $id = static fn (array $document): string => $document['paths']['/api/versioned-forms']['get']['parameters'][0]['x-docuccino']['id'];

    expect($id($head))->toStartWith('par:v1:')
        // Two documents are two operations, so the parameter is two nodes and not one shared identity.
        ->and($id($older))->not->toBe($id($head));
});

it('names the header the document configures', function (): void {
    $document = generateDocument(static function (array $raw): array {
        $raw['api_version']['header'] = 'Api-Version';

        return $raw;
    }, 'v2026-06-01')->document->toArray();

    $names = array_map(
        static fn (array $parameter): string => $parameter['name'],
        $document['paths']['/api/versioned-forms']['get']['parameters'],
    );

    expect($names)->toContain('Api-Version')->and($names)->not->toContain('X-Api-Version');
});

it('leaves an application that documents the header itself to say it its own way', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms/documented', [VersionedFormController::class, 'documented']);

    $document = generateDocument(static function (array $raw): array {
        $raw['routes'] = ['include' => ['api/versioned-forms/documented']];

        return $raw;
    }, 'v2026-06-01')->document->toArray();

    $parameters = $document['paths']['/api/versioned-forms/documented']['get']['parameters'];

    // One parameter, and the author's: two of one name in one location is a document no client can read.
    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]['description'])->toBe('Pin the API version, or take the current one.')
        ->and($parameters[0]['schema'])->not->toHaveKey('enum');
});

it('leaves a document that declares no version untouched', function (): void {
    // The head-document guarantee the six committed goldens depend on: a document with no `api_version`
    // is not an API version, and nothing here moves a byte of it.
    $versioned = generateDocument(key: 'v2026-09-01')->document->toArray();
    $plain = generateDocument(static function (array $raw): array {
        unset($raw['api_version']);
        $raw['info']['version'] = '1.0.0';

        return $raw;
    }, 'v2026-09-01')->document->toArray();

    expect($plain['info']['version'])->toBe('1.0.0')
        ->and($plain['paths']['/api/versioned-forms']['get'])->not->toHaveKey('parameters')
        ->and($plain['components']['schemas']['FormData']['properties'])->toHaveKey('title')
        // And the versioned one really did move: a no-op comparison against a no-op proves nothing.
        ->and($versioned['paths']['/api/versioned-forms']['get']['parameters'])->not->toBeEmpty();
});

it('emits a valid document for every version', function (string $key): void {
    $result = generateDocument(key: $key);

    $invalid = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'document.schema-invalid',
    ));

    expect($invalid)->toBe([])
        // Through the real emitter too, so the rewritten node is canonicalised and hashed like any other.
        ->and((new UirEmitter)->emit($result->document))->toContain('"X-Api-Version"');
})->with(['v2026-09-01', 'v2026-06-01']);

it('says nothing about versioning while deriving a version that needs no change', function (): void {
    $codes = array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code,
        generateDocument(key: 'v2026-09-01')->diagnostics,
    );

    expect(array_filter($codes, static fn (string $code): bool => str_starts_with($code, 'versioning.')))->toBe([]);
});

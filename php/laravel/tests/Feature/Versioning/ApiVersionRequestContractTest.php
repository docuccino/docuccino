<?php

declare(strict_types=1);

use Docuccino\Laravel\Testing\ApiContract;
use Illuminate\Routing\Router;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Http\Controllers\VersionedFormController;
use Workbench\App\Http\Middleware\DowngradeToPinnedApiVersion;
use Workbench\App\Http\Middleware\UpgradeFromPinnedApiVersion;

/**
 * The check a request rename earns its place with: replay a real request spelling the field the way an
 * older version accepted it, with that version pinned, and require BOTH halves of the exchange to
 * validate against that version's document.
 *
 * A request rename is falsifiable in a way most of the vocabulary is not. A response verb can only be
 * caught by a response that came out wrong; this one is caught by the application refusing a request it
 * documents as valid — which is a client locked out rather than a client mildly misinformed, and it is
 * the failure a version history introduces most easily, because the inbound migration is the half
 * nobody looks at.
 *
 * Every request goes through `postJson()`, so the router, the middleware and the FormRequest's own
 * validation really run.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));

    /** @var Router $router */
    $router = app('router');
    $router->middleware([UpgradeFromPinnedApiVersion::class])
        ->post('api/versioned-forms', [VersionedFormController::class, 'store']);
    $router->get('api/versioned-search', [VersionedFormController::class, 'search']);

    config()->set('docuccino.documents', versionedRequestDocuments());
});

afterEach(function (): void {
    @unlink(workbenchContractPath('r2026-09-01'));
    @unlink(workbenchContractPath('r2026-06-01'));

    // All of ApiContract's state is static and memoised for the process.
    ApiContract::reset();
});

it('documents the field the code accepts today in the version the rename shipped in', function (): void {
    bindVersionedRequestEngine();
    $schema = generateDocument(key: 'r2026-09-01')->document->toArray()['components']['schemas']['StoreVersionedFormRequest'];

    expect(array_keys($schema['properties']))->toBe(['title'])
        ->and($schema['required'])->toBe(['title']);
});

it('documents the former field name in a version older than the change', function (): void {
    bindVersionedRequestEngine();
    $schema = generateDocument(key: 'r2026-06-01')->document->toArray()['components']['schemas']['StoreVersionedFormRequest'];

    expect(array_keys($schema['properties']))->toBe(['name'])
        // Load-bearing on the way IN: a `required` still naming today's field marks a body carrying the
        // old spelling invalid, which is a client refused by the document that promised to accept it.
        ->and($schema['required'])->toBe(['name'])
        ->and($schema['properties'])->not->toHaveKey('title');
});

/*
 * The examples half, and the finding it records: `ChangedFieldExamples` already descends through
 * `requestBody.content.*`, so a request rename needed no extension of the rewriter at all — this is the
 * assertion that the walk really reaches that position rather than the rewriter being handed a document
 * it never descends into. An example a consumer copies and posts back has to be the shape the version's
 * own schema accepts, or the document contradicts itself in the one member anybody copies.
 */
it('rewrites the example published beside a renamed request body', function (): void {
    bindVersionedRequestEngine();

    $media = static fn (string $key): array => generateDocument(key: $key)->document->toArray()['paths']['/api/versioned-forms']['post']['requestBody']['content']['application/json'];

    expect($media('r2026-09-01')['example'])->toBe(['title' => 'Onboarding'])
        ->and($media('r2026-06-01')['example'])->toBe(['name' => 'Onboarding']);
});

it('accepts and documents a request written the way the older version accepted it', function (): void {
    workbenchContract(key: 'r2026-06-01', bindEngine: bindVersionedRequestEngine(...));

    $response = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-06-01')
        ->postJson('api/versioned-forms', ['name' => 'Onboarding']);

    // The contract FIRST, and no status assertion in front of it: it is the assertion that has to catch
    // a runtime whose inbound migration stopped working, and a shape check standing ahead of it would
    // fail before the contract was ever consulted. Executed rather than claimed — disabling the
    // upgrade middleware makes this line fail with "responded 422, which the contract does not
    // document (it documents 201)", which is the runtime locking a pinned client out.
    ApiContract::assertions()->assertValidRequest($response);
    ApiContract::assertions()->assertValidResponse($response);
    ApiContract::assertions()->assertValidExamples();

    // And then what the application actually did with the older spelling.
    expect($response->status())->toBe(201)
        ->and($response->json())->toBe(['id' => 3, 'title' => 'Onboarding', 'publishedAt' => null]);
});

it('accepts and documents a request written the way the code accepts it, at the head version', function (): void {
    workbenchContract(key: 'r2026-09-01', bindEngine: bindVersionedRequestEngine(...));

    $response = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-09-01')
        ->postJson('api/versioned-forms', ['title' => 'Onboarding'])
        ->assertCreated();

    ApiContract::assertions()->assertValidRequest($response);
    ApiContract::assertions()->assertValidExamples();
});

/*
 * The other half, and the reason the two above are worth anything: the check has to be able to FAIL. A
 * request sent at the head shape is checked against the older version's document, which is exactly what
 * a client that ignored the version header produces — and the assertion refuses it, naming the field the
 * older version demands.
 */
it('refuses a head-shaped request against the older version, naming the field it demands', function (): void {
    workbenchContract(key: 'r2026-06-01', bindEngine: bindVersionedRequestEngine(...));

    // Nothing pinned, so the application validates today's spelling while the contract is the older
    // version's — and it really was accepted, which is what makes the refusal below about the DOCUMENT.
    $response = $this->postJson('api/versioned-forms', ['title' => 'Onboarding'])->assertCreated();

    try {
        ApiContract::assertions()->assertValidRequest($response);
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('POST /api/versioned-forms')
            ->toContain('name');

        return;
    }

    throw new RuntimeException('The older version accepted a request carrying the field it renamed.');
});

/*
 * And the mirror, which catches the mistake an inbound migration actually makes: firing when it should
 * not. A body written the OLD way, checked against the HEAD document, is what a runtime that kept
 * downgrading past its own version produces.
 */
it('refuses an old-shaped request against the head version', function (): void {
    workbenchContract(key: 'r2026-09-01', bindEngine: bindVersionedRequestEngine(...));

    // Pinned to the head, so the upgrade middleware deliberately does not fire; the FormRequest refuses
    // the body, and the contract has to refuse it too rather than passing an unvalidated exchange.
    $response = $this->withHeader(DowngradeToPinnedApiVersion::HEADER, '2026-09-01')
        ->postJson('api/versioned-forms', ['name' => 'Onboarding']);

    try {
        ApiContract::assertions()->assertValidRequest($response);
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())->toContain('title');

        return;
    }

    throw new RuntimeException('The head version accepted a request spelling the field the way it was renamed FROM.');
});

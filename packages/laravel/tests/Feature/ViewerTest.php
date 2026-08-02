<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Support\Facades\Gate;

/**
 * The runtime viewer endpoints (design §5 serving): Gate-guarded HTML + `.json` + a locally bundled
 * Scalar asset (no runtime CDN by default).
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

it('denies access by default outside the local environment', function (): void {
    // Testbench runs as the "testing" environment and no gate is configured, so access is denied.
    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
});

it('allows access when the configured gate passes', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    $this->get('/docs/api')->assertOk();
});

it('serves the Scalar HTML referencing the spec URL and the locally bundled asset', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    $response = $this->get('/docs/api');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('id="api-reference"', false)
        // The spec URL points at the document's .json endpoint...
        ->assertSee('data-url="'.url('/docs/api.json').'"', false)
        // ...and the Scalar script is the local asset, never a CDN.
        ->assertSee('src="'.url('/docs/api/assets/scalar.js').'"', false)
        ->assertDontSee('cdn.jsdelivr.net', false);
});

it('serves the generated OpenAPI JSON', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    $response = $this->get('/docs/api.json');

    $response->assertOk()->assertHeader('Content-Type', 'application/json');
    expect($response->getContent())
        ->toBe(file_get_contents(dirname(__DIR__).'/Fixtures/golden/workbench.openapi.json'));
});

it('serves the locally bundled Scalar asset as JavaScript', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    $this->get('/docs/api/assets/scalar.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript')
        ->assertSee('api-reference', false);
});

it('opts into the CDN when viewer.cdn is true', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    config()->set('docuccino.documents.default.viewer.cdn', true);
    Gate::before(static fn ($user = null): bool => true);

    $this->get('/docs/api')
        ->assertOk()
        ->assertSee('cdn.jsdelivr.net/npm/@scalar/api-reference', false);
});

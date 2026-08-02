<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * Security scheme configuration → document (design §Auth detection / §Security scheme breadth):
 * the scheme catalogue, document-level and per-operation requirements, middleware auto-detection,
 * and the #[Unauthenticated] opt-out.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
 * @return array<string, mixed>
 */
function generateSecurity(callable $mutate): array
{
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $raw = $mutate($raw);

    $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');

    return app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document->toArray();
}

it('emits the full scheme set and a document-level requirement', function (): void {
    $document = generateSecurity(function (array $raw): array {
        $raw['security']['schemes'] = [
            'bearer' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
            'basic' => ['type' => 'http', 'scheme' => 'basic'],
            'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            'oauth2' => ['type' => 'oauth2', 'flows' => [
                'authorizationCode' => [
                    'authorizationUrl' => 'https://example.test/oauth/authorize',
                    'tokenUrl' => 'https://example.test/oauth/token',
                    'scopes' => ['read' => 'Read access'],
                ],
            ]],
            'oidc' => ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://example.test/.well-known/openid-configuration'],
        ];
        $raw['security']['document'] = [['bearer' => []]];

        return $raw;
    });

    expect($document['components']['securitySchemes'])->toHaveKeys(['bearer', 'basic', 'apiKey', 'oauth2', 'oidc'])
        ->and($document['components']['securitySchemes']['apiKey'])->toBe(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'])
        ->and($document['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes'])->toBe(['read' => 'Read access'])
        ->and($document['security'])->toBe([['bearer' => []]]);
});

it('applies the default requirement to auth-detected routes only, and clears it for #[Unauthenticated]', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/protected', [FormController::class, 'index'])->middleware('auth:sanctum');

    $document = generateSecurity(function (array $raw): array {
        $raw['security']['schemes'] = ['bearer' => ['type' => 'http', 'scheme' => 'bearer']];
        $raw['security']['default'] = [['bearer' => []]];

        return $raw;
    });

    // The auth-middleware route inherits the default requirement...
    expect($document['paths']['/api/protected']['get']['security'])->toBe([['bearer' => []]])
        // ...a route with no auth middleware carries none...
        ->and($document['paths']['/api/forms']['get'])->not->toHaveKey('security')
        // ...and #[Unauthenticated] explicitly opts out.
        ->and($document['paths']['/api/widgets']['post']['security'])->toBe([]);
});

it('supports a multi-scheme AND requirement', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/protected', [FormController::class, 'index'])->middleware('auth');

    $document = generateSecurity(function (array $raw): array {
        $raw['security']['default'] = [['bearer' => [], 'apiKey' => []]];

        return $raw;
    });

    // A single requirement object listing two schemes means BOTH are required.
    expect($document['paths']['/api/protected']['get']['security'])->toBe([['bearer' => [], 'apiKey' => []]]);
});

it('emits no security by default', function (): void {
    $document = generateSecurity(static fn (array $raw): array => $raw);

    expect($document)->not->toHaveKey('security')
        ->and($document['components'] ?? [])->not->toHaveKey('securitySchemes');
});

<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Sanctum\SanctumDetector;
use Docuccino\Laravel\Integrations\Sanctum\SanctumScheme;

const STATEFUL = 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful';

it('detects the active Sanctum modes across the detection combinations', function (array $middleware, array $modes): void {
    expect((new SanctumDetector)->supportedModes($middleware))->toBe($modes);
})->with([
    'token only (auth:sanctum)' => [['auth:sanctum'], ['token']],
    'token only (bare sanctum alias)' => [['sanctum'], ['token']],
    'token only (multi-guard list)' => [['auth:web,sanctum'], ['token']],
    'stateful only (cookie SPA, web guard)' => [[STATEFUL, 'auth:web'], ['stateful']],
    'both modes (dual auth on one route)' => [['auth:sanctum', STATEFUL], ['token', 'stateful']],
    'neither (plain web auth)' => [['auth:web'], []],
    'neither (api guard, no sanctum)' => [['auth:api'], []],
]);

it('builds the token and stateful schemes with auth-section prose', function (): void {
    $token = SanctumScheme::token();
    $stateful = SanctumScheme::stateful('laravel_session');

    expect($token['type'])->toBe('http')
        ->and($token['scheme'])->toBe('bearer')
        ->and($token['description'])->toContain('Bearer');

    expect($stateful['type'])->toBe('apiKey')
        ->and($stateful['in'])->toBe('cookie')
        ->and($stateful['name'])->toBe('laravel_session')
        ->and($stateful['description'])->toContain('X-XSRF-TOKEN');
});

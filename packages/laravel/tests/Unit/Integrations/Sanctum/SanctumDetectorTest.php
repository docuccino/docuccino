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
    'token only (abilities middleware)' => [['auth:sanctum', 'abilities:read'], ['token']],
    'token only (ability short alias)' => [['ability:read'], ['token']],
    'token only (CheckAbilities ::using FQCN)' => [['Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities:read,write'], ['token']],
    'token only (CheckForAnyAbility ::using FQCN)' => [['Laravel\\Sanctum\\Http\\Middleware\\CheckForAnyAbility:read'], ['token']],
    'stateful only (cookie SPA, web guard)' => [[STATEFUL, 'auth:web'], ['stateful']],
    'both modes (dual auth on one route)' => [['auth:sanctum', STATEFUL], ['token', 'stateful']],
    // The false-positive guard: statefulApi() prepends the stateful middleware to the whole api
    // group, so a PUBLIC route (no auth guard) carrying only that middleware must yield NO modes.
    'public route (stateful middleware, no auth guard)' => [[STATEFUL], []],
    'public route (stateful middleware + throttle, no auth)' => [[STATEFUL, 'throttle:60,1'], []],
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

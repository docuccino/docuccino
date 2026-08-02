<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\RateLimit\ThrottleParser;

/**
 * Dataset coverage over every `throttle` middleware form plus the non-throttle degradation (the map
 * is the parse table; one form is not coverage).
 */
it('parses each throttle middleware form', function (string $middleware, ?int $max, ?int $decay, ?string $name): void {
    $limit = (new ThrottleParser)->parse($middleware);

    expect($limit)->not->toBeNull();
    expect($limit->maxAttempts)->toBe($max)
        ->and($limit->decayMinutes)->toBe($decay)
        ->and($limit->name)->toBe($name)
        ->and($limit->isNamed())->toBe($name !== null);
})->with([
    'numeric with decay' => ['throttle:60,1', 60, 1, null],
    'numeric without decay defaults to 1 minute' => ['throttle:100', 100, 1, null],
    'numeric with wider window' => ['throttle:30,5', 30, 5, null],
    'named limiter' => ['throttle:api', null, null, 'api'],
    'bare throttle is a named default limiter' => ['throttle', null, null, 'default'],
]);

it('returns null for a non-throttle middleware', function (string $middleware): void {
    expect((new ThrottleParser)->parse($middleware))->toBeNull();
})->with([
    'auth' => ['auth:sanctum'],
    'unrelated' => ['bindings'],
    'throttle-like prefix' => ['throttler:60'],
]);

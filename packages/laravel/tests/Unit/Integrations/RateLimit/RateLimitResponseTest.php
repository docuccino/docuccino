<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\RateLimit\RateLimitResponse;
use Docuccino\Laravel\Integrations\RateLimit\ThrottleLimit;

it('builds a 429 with the concrete numbers as examples for a numeric throttle', function (): void {
    $response = (new RateLimitResponse)->build(new ThrottleLimit(maxAttempts: 60, decayMinutes: 2.0));

    expect($response['headers'])->toHaveKeys(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset']);
    expect($response['headers']['X-RateLimit-Limit']['schema'])->toBe(['type' => 'integer', 'example' => 60])
        ->and($response['headers']['Retry-After']['schema'])->toBe(['type' => 'integer', 'example' => 120])
        ->and($response['headers']['X-RateLimit-Reset']['schema'])->toBe(['type' => 'integer']);
    expect($response['content']['application/json']['schema']['properties'])->toHaveKey('message');
});

it('rounds a float decay to whole seconds for the Retry-After example', function (): void {
    $response = (new RateLimitResponse)->build(new ThrottleLimit(maxAttempts: 60, decayMinutes: 0.5));

    expect($response['headers']['Retry-After']['schema'])->toBe(['type' => 'integer', 'example' => 30]);
});

it('notes the guest allowance in the description for the pipe form', function (): void {
    $response = (new RateLimitResponse)->build(new ThrottleLimit(maxAttempts: 60, decayMinutes: 1.0, guestMaxAttempts: 10));

    expect($response['headers']['X-RateLimit-Limit']['schema'])->toBe(['type' => 'integer', 'example' => 60])
        ->and($response['description'])->toContain('unauthenticated requests are limited to 10');
});

it('builds a 429 without numeric examples for a named limiter', function (): void {
    $response = (new RateLimitResponse)->build(new ThrottleLimit(name: 'api'));

    expect($response['headers']['X-RateLimit-Limit']['schema'])->toBe(['type' => 'integer'])
        ->and($response['headers']['Retry-After']['schema'])->toBe(['type' => 'integer']);
});

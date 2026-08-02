<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\RateLimit\RateLimitResponse;
use Docuccino\Laravel\Integrations\RateLimit\ThrottleLimit;

it('builds a 429 with the concrete numbers as examples for a numeric throttle', function (): void {
    $response = (new RateLimitResponse)->build(new ThrottleLimit(maxAttempts: 60, decayMinutes: 2));

    expect($response['headers'])->toHaveKeys(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining']);
    expect($response['headers']['X-RateLimit-Limit']['schema'])->toBe(['type' => 'integer', 'example' => 60])
        ->and($response['headers']['Retry-After']['schema'])->toBe(['type' => 'integer', 'example' => 120]);
    expect($response['content']['application/json']['schema']['properties'])->toHaveKey('message');
});

it('builds a 429 without numeric examples for a named limiter', function (): void {
    $response = (new RateLimitResponse)->build(new ThrottleLimit(name: 'api'));

    expect($response['headers']['X-RateLimit-Limit']['schema'])->toBe(['type' => 'integer'])
        ->and($response['headers']['Retry-After']['schema'])->toBe(['type' => 'integer']);
});

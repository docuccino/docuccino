<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Passport\OAuth2Scheme;
use Docuccino\Laravel\Integrations\Passport\ScopeMiddlewareParser;

it('extracts scopes from every scope-middleware form', function (array $middleware, array $expected): void {
    expect((new ScopeMiddlewareParser)->scopes($middleware))->toBe($expected);
})->with([
    'scope (any-of)' => [['scope:read'], ['read']],
    'scopes (all-of), multiple' => [['scopes:read,write'], ['read', 'write']],
    'both forms union, deduped in order' => [['scope:read', 'scopes:write,read'], ['read', 'write']],
    'spaces trimmed' => [['scopes:read, write'], ['read', 'write']],
    'no scope middleware' => [['auth:api'], []],
    'scope-like prefix ignored' => [['scoped:read'], []],
]);

it('builds an oauth2 scheme with Passport flows over its conventional endpoints', function (): void {
    $scheme = OAuth2Scheme::passport('https://api.example.com');

    expect($scheme['type'])->toBe('oauth2')
        ->and(array_keys($scheme['flows']))->toBe(['authorizationCode', 'clientCredentials', 'password']);

    expect($scheme['flows']['authorizationCode']['authorizationUrl'])->toBe('https://api.example.com/oauth/authorize')
        ->and($scheme['flows']['authorizationCode']['tokenUrl'])->toBe('https://api.example.com/oauth/token')
        ->and($scheme['flows']['clientCredentials']['scopes'])->toBe(['*' => 'Full access to the API']);
});

it('normalises a trailing slash on the base URL', function (): void {
    $scheme = OAuth2Scheme::passport('https://api.example.com/');

    expect($scheme['flows']['password']['tokenUrl'])->toBe('https://api.example.com/oauth/token');
});

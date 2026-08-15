<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Integrations\Support\MachineDependentValue;

/**
 * The shared rule behind the machine-dependent-value reports. The host table is a lookup table, so it
 * is driven over every entry it holds — but the row that carries the contract is the NEGATIVE one: a
 * document built against a real public API must stay silent, or the warning is noise and
 * `--fail-on=warning` becomes something teams switch off.
 */
it('reads a loopback or local-development host as machine-dependent', function (string $url): void {
    expect(MachineDependentValue::isLocalUrl($url))->toBeTrue();
})->with([
    // Every entry of the loopback table, each spelled the way a URL carries it.
    'localhost' => ['http://localhost'],
    '127.0.0.1' => ['http://127.0.0.1:8000/oauth'],
    '0.0.0.0' => ['http://0.0.0.0:80'],
    '::1 in IPv6 brackets' => ['http://[::1]:8000/oauth'],

    // Every entry of the reserved-suffix table.
    '.localhost' => ['http://acme.localhost'],
    '.test' => ['https://acme.test/api'],
    '.local' => ['https://acme.local'],
    '.example' => ['https://api.acme.example'],

    // Spellings of the same names a case-sensitive or dot-blind check would wave through.
    'an upper-case host' => ['http://LOCALHOST/oauth'],
    'a fully-qualified trailing dot' => ['https://acme.test./api'],
    'a deeper subdomain of a reserved suffix' => ['https://auth.eu.acme.test'],
]);

it('reads anything else as a fine thing to publish', function (string $url): void {
    expect(MachineDependentValue::isLocalUrl($url))->toBeFalse();
})->with([
    // THE row that matters: a real deployment must not be reported.
    'a public https URL' => ['https://api.acme.com'],
    'a public URL on a port' => ['https://auth.acme.co.uk:8443/oauth'],

    // Names that merely CONTAIN a reserved word, which a substring check would claim.
    'a host containing "localhost"' => ['https://localhost.acme.com'],
    'a host containing "test"' => ['https://test.acme.com'],
    'a public host ending in the word test' => ['https://acme-test.com'],
    'a public host whose label is example' => ['https://example.com'],

    // Nothing to go on rather than evidence of trouble: guessing here would report a real API.
    'a relative path' => ['/oauth'],
    'an empty string' => [''],
    'a value that is not a URL at all' => ['laravel_session'],
]);

it('reports an unpinned local URL as a warning naming the value, the key and the pin', function (): void {
    $diagnostic = MachineDependentValue::forUrl(
        'The Passport scheme', 'http://localhost', 'app.url', 'integrations.passport.url', 'GET api/x',
    );

    expect($diagnostic)->not->toBeNull()
        ->and($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->code)->toBe('config.machine-dependent-value')
        ->and($diagnostic->routeSignature)->toBe('GET api/x')
        ->and($diagnostic->message)->toContain('http://localhost')
        ->and($diagnostic->message)->toContain('app.url')
        ->and($diagnostic->help)->not->toBeNull()
        ->and($diagnostic->help)->toContain('integrations.passport.url');
});

it('reports nothing for a URL a consumer can actually call', function (): void {
    expect(MachineDependentValue::forUrl(
        'The Passport scheme', 'https://auth.acme.com', 'app.url', 'integrations.passport.url',
    ))->toBeNull();
});

it('reports an unpinned opaque value on where it came from, since it has no host to judge', function (): void {
    $diagnostic = MachineDependentValue::forValue(
        'The Sanctum stateful scheme', 'acme_crm_session', 'session.cookie', 'integrations.sanctum.cookie',
    );

    expect($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->code)->toBe('config.machine-dependent-value')
        ->and($diagnostic->routeSignature)->toBeNull()
        ->and($diagnostic->message)->toContain('acme_crm_session')
        ->and($diagnostic->message)->toContain('session.cookie')
        ->and($diagnostic->message)->toContain('environment')
        ->and($diagnostic->help)->toContain('integrations.sanctum.cookie');
});

it('reports a value no config key supplied as the fallback default it is', function (): void {
    $diagnostic = MachineDependentValue::forDefault(
        'The Sanctum stateful scheme', 'laravel_session', 'session.cookie', 'integrations.sanctum.cookie',
    );

    expect($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->code)->toBe('config.machine-dependent-value')
        ->and($diagnostic->message)->toContain('laravel_session')
        ->and($diagnostic->message)->toContain('session.cookie')
        ->and($diagnostic->message)->toContain('fallback default')
        ->and($diagnostic->help)->toContain('integrations.sanctum.cookie');
});

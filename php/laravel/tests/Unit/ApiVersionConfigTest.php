<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Config\ConfiguredDocuments;

/**
 * The config half of API versioning: what makes a document a version, where its changes live, and the
 * closed set of versions the header enumerates.
 */

/** @param array<string, mixed> $apiVersion */
function versionedConfig(mixed $apiVersion): DocumentConfig
{
    return new DocumentConfig(key: 'v', info: [], raw: ['api_version' => $apiVersion]);
}

it('is not an API version until it says which one it is', function (mixed $apiVersion): void {
    expect(versionedConfig($apiVersion)->apiVersion())->toBeNull();
})->with([
    'no bag' => [null],
    'a bag with no version' => [['changes' => ['dir' => 'app/Api/Versions']]],
    'an empty version' => [['version' => '']],
    'a version that is not a string' => [['version' => 20260901]],
    'a bag that is not a bag' => ['2026-09-01'],
]);

it('reads the version the document declares', function (): void {
    expect(versionedConfig(['version' => '2026-09-01'])->apiVersion())->toBe('2026-09-01');
});

it('reads the changes directory, and refuses one no filesystem call could hold', function (): void {
    expect(versionedConfig(['version' => '2026-09-01', 'changes' => ['dir' => 'app/Api/Versions']])->apiVersionChangesDir())
        ->toBe('app/Api/Versions')
        ->and(versionedConfig(['version' => '2026-09-01'])->apiVersionChangesDir())->toBeNull()
        ->and(versionedConfig(['version' => '2026-09-01', 'changes' => ['dir' => '']])->apiVersionChangesDir())->toBeNull()
        // A NUL byte reaches no `is_dir()` from here: the same refusal every other configured path gets.
        ->and(versionedConfig(['version' => '2026-09-01', 'changes' => ['dir' => "app\0/Api"]])->apiVersionChangesDir())->toBeNull();
});

it('publishes X-Api-Version unless the document names another header', function (mixed $configured, string $header): void {
    expect(versionedConfig(['version' => '2026-09-01', 'header' => $configured])->apiVersionHeader())->toBe($header);
})->with([
    'unset' => [null, 'X-Api-Version'],
    'blank' => ['   ', 'X-Api-Version'],
    'not a string' => [false, 'X-Api-Version'],
    'named' => ['Api-Version', 'Api-Version'],
    'padded' => ['  Api-Version  ', 'Api-Version'],
]);

it('reads the closed set of versions off the documents themselves, sorted', function (): void {
    config()->set('docuccino.documents', [
        'later' => ['api_version' => ['version' => '2026-12-01']],
        'earlier' => ['api_version' => ['version' => '2026-06-01']],
        // A document that is not a version contributes nothing, and neither does a malformed one.
        'plain' => ['info' => ['version' => '1.0.0']],
        'broken' => ['api_version' => ['version' => '']],
        'duplicate' => ['api_version' => ['version' => '2026-06-01']],
    ]);

    expect((new ConfiguredDocuments)->apiVersions())->toBe(['2026-06-01', '2026-12-01']);
});

it('enumerates nothing when the application configures no version', function (): void {
    config()->set('docuccino.documents', ['default' => ['info' => ['version' => '1.0.0']]]);

    expect((new ConfiguredDocuments)->apiVersions())->toBe([]);
});

<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Config\ConfiguredDocuments;

/**
 * The config half of API versioning: what makes a document a version, where its changes live, and the
 * closed set of versions the header enumerates.
 */
function versionedConfig(mixed $apiVersion, mixed $version = '2026-09-01'): DocumentConfig
{
    return new DocumentConfig(key: 'v', info: [], raw: ['api_version' => $apiVersion, 'info' => ['version' => $version]]);
}

it('is an API version whenever it declares one, whatever the bag holds', function (mixed $apiVersion, bool $declares): void {
    expect(versionedConfig($apiVersion)->declaresApiVersion())->toBe($declares);
})->with([
    'no bag' => [null, false],
    'a bag that is not a bag' => ['2026-09-01', false],
    'an empty bag' => [[], true],
    'a bag naming a changes directory' => [['changes' => ['dir' => 'app/Api/Versions']], true],
]);

it('states no version until info.version says one that is not the placeholder', function (mixed $version): void {
    expect(versionedConfig([], $version)->apiVersion())->toBeNull();
})->with([
    'unset' => [null],
    'empty' => [''],
    'blank' => ['   '],
    'not a string' => [20260901],
    // The shipped default is a placeholder, not a version anybody serves.
    'the placeholder' => ['1.0.0'],
]);

it('reads the version off info.version, which is the only place it is written', function (): void {
    expect(versionedConfig([], '2026-09-01')->apiVersion())->toBe('2026-09-01')
        ->and(versionedConfig([], '  2026-09-01  ')->apiVersion())->toBe('2026-09-01')
        // A document that is not a version has no API version, whatever its info says.
        ->and((new DocumentConfig(key: 'v', info: [], raw: ['info' => ['version' => '2026-09-01']]))->apiVersion())->toBeNull();
});

it('reads the changes directory, and refuses one no filesystem call could hold', function (): void {
    expect(versionedConfig(['changes' => ['dir' => 'app/Api/Versions']])->apiVersionChangesDir())
        ->toBe('app/Api/Versions')
        ->and(versionedConfig([])->apiVersionChangesDir())->toBeNull()
        ->and(versionedConfig(['changes' => ['dir' => '']])->apiVersionChangesDir())->toBeNull()
        // A NUL byte reaches no `is_dir()` from here: the same refusal every other configured path gets.
        ->and(versionedConfig(['changes' => ['dir' => "app\0/Api"]])->apiVersionChangesDir())->toBeNull();
});

it('publishes X-Api-Version unless the document names another header', function (mixed $configured, string $header): void {
    expect(versionedConfig(['header' => $configured])->apiVersionHeader())->toBe($header);
})->with([
    'unset' => [null, 'X-Api-Version'],
    'blank' => ['   ', 'X-Api-Version'],
    'not a string' => [false, 'X-Api-Version'],
    'named' => ['Api-Version', 'Api-Version'],
    'padded' => ['  Api-Version  ', 'Api-Version'],
]);

it('reads the closed set of versions off the documents themselves, sorted', function (): void {
    config()->set('docuccino.documents', [
        'later' => ['api_version' => [], 'info' => ['version' => '2026-12-01']],
        'earlier' => ['api_version' => [], 'info' => ['version' => '2026-06-01']],
        // A document that is not a version contributes nothing, and neither does one still at the
        // placeholder — its own build says so rather than the enum publishing a version nobody serves.
        'plain' => ['info' => ['version' => '2027-01-01']],
        'placeholder' => ['api_version' => [], 'info' => ['version' => '1.0.0']],
        'duplicate' => ['api_version' => [], 'info' => ['version' => '2026-06-01']],
    ]);

    expect((new ConfiguredDocuments)->apiVersions())->toBe(['2026-06-01', '2026-12-01']);
});

it('enumerates nothing when the application configures no version', function (): void {
    config()->set('docuccino.documents', ['default' => ['info' => ['version' => '1.0.0']]]);

    expect((new ConfiguredDocuments)->apiVersions())->toBe([]);
});

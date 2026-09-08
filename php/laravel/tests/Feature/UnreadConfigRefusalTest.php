<?php

declare(strict_types=1);

use Docuccino\Laravel\Commands\RefusesUnreadConfig;
use Docuccino\Laravel\DocuccinoServiceProvider;
use Docuccino\Laravel\Tests\Support\BuildSettings;
use Illuminate\Container\Container;
use Spatie\LaravelPackageTools\Package;

/**
 * An application with no `docuccino.yaml` and its build settings still in `config/docuccino.php` is
 * not read at all: the document would be assembled from defaults rather than from what its author
 * wrote. `config.not-migrated` says so, and the commands stop — before the build, and whatever
 * `--fail-on` asks for.
 *
 * `--fail-on` is a gate over what a build FOUND, so its quietest setting is `none` and it is also the
 * default: an error raised from inside the build printed and still exited 0, after a full analysis,
 * having written the wrong artifact. {@see ExportDiagnostics} already refuses a document that cannot
 * say where its artifacts go on exactly those terms, and this follows it rather than inventing a
 * second status.
 *
 * Every registered command carries a ROW here, refusing or not, and the rows are held to the
 * provider's own registration — the two guards would otherwise cover their two subsets and say
 * nothing about a command added to neither.
 */

/** @return list<class-string> Every command the provider registers. */
function registeredDocuccinoCommands(): array
{
    $package = new Package;
    (new DocuccinoServiceProvider(new Container))->configurePackage($package);

    /** @var list<class-string> $commands */
    $commands = $package->commands;

    return $commands;
}

/**
 * Command class => whether it must refuse, and how it is invoked. Written out rather than read off the
 * traits, because a guard that asked the commands which of them refuse would agree with any answer.
 *
 * @return array<string, array{class-string, bool, array<string, mixed>}>
 */
function unreadConfigRefusalRows(): array
{
    return [
        // Everything that reads the configuration to produce or check a document.
        'export' => ['Docuccino\Laravel\Commands\ExportCommand', true, ['--format' => 'uir']],
        'validate' => ['Docuccino\Laravel\Commands\ValidateCommand', true, []],
        'cache' => ['Docuccino\Laravel\Commands\CacheCommand', true, []],
        'diff' => ['Docuccino\Laravel\Commands\DiffCommand', true, ['old' => 'docs/openapi.json']],
        'coverage' => ['Docuccino\Laravel\Commands\CoverageCommand', true, []],
        'explain' => ['Docuccino\Laravel\Commands\ExplainCommand', true, ['route' => 'GET /api/forms']],
        'version-changes' => ['Docuccino\Laravel\Commands\VersionChangesCommand', true, ['old' => 'docs/openapi.json']],
        'watch' => ['Docuccino\Laravel\Commands\WatchCommand', true, []],
        // The remedy: refusing to run it would leave the reader with no way out of the state.
        'install' => ['Docuccino\Laravel\Commands\InstallCommand', false, ['--no-export' => true]],
        // Reads no configuration. Emptying a cache built from the wrong settings is the one thing
        // still worth doing here, so it is not gated on fixing them.
        'clear' => ['Docuccino\Laravel\Commands\ClearCommand', false, []],
    ];
}

/** No `docuccino.yaml`, and two build settings left behind in the framework config. */
function arrangeUnmigrated(): void
{
    BuildSettings::none();
    config()->set('docuccino.documents.default.routes.include', ['api/*']);
    config()->set('docuccino.on_route_error', 'omit');
}

it('gives every registered command a row', function (): void {
    $rows = [];
    foreach (unreadConfigRefusalRows() as [$class, $refuses, $arguments]) {
        $rows[] = $class;
    }

    // The union, against the domain itself: a command registered and listed in neither half of this
    // file would otherwise be checked by nothing at all.
    sort($rows);
    $registered = registeredDocuccinoCommands();
    sort($registered);

    expect($rows)->toBe($registered)
        ->and($rows)->toHaveCount(10);
});

it('declares the refusal exactly where the rows say it does', function (): void {
    // The trait is what makes a command refuse, so the rows and the code are held to each other. A
    // command that grew the trait without a row here — or lost it with the row still claiming it —
    // fails on this line rather than at the next report from an application.
    foreach (unreadConfigRefusalRows() as $name => [$class, $refuses, $arguments]) {
        expect(in_array(RefusesUnreadConfig::class, class_uses_recursive($class), true))
            ->toBe($refuses, $name.' disagrees with its row');
    }
});

it('refuses, naming the code, before it builds anything', function (string $name): void {
    [$class, $refuses, $arguments] = unreadConfigRefusalRows()[$name];

    arrangeUnmigrated();
    bindStubEngine();
    scriptWatch(1);

    // No `--fail-on` at all: the default is `none`, which is the setting the printed error used to
    // exit 0 under. Nothing here can turn the refusal off.
    test()->artisan($class, $arguments)
        ->expectsOutputToContain('config.not-migrated')
        ->expectsOutputToContain('docuccino:install')
        ->assertExitCode(1);
})->with(array_keys(array_filter(unreadConfigRefusalRows(), static fn (array $row): bool => $row[1])));

it('refuses even where the run explicitly asks for no gate', function (): void {
    // `--fail-on=none` is a project saying "report, do not fail". It is a say over what a build FOUND,
    // never over whether the configuration was read at all.
    arrangeUnmigrated();
    bindStubEngine();

    test()->artisan('docuccino:export', ['--format' => 'uir', '--fail-on' => 'none'])
        ->expectsOutputToContain('config.not-migrated')
        ->assertExitCode(1);
});

it('writes nothing while it refuses', function (): void {
    // The half a printed error never gave: the artifact assembled from defaults must not reach disk.
    arrangeUnmigrated();
    bindStubEngine();

    $out = sys_get_temp_dir().'/docuccino-refused-'.uniqid().'.json';

    try {
        test()->artisan('docuccino:export', ['--format' => 'uir', '--out' => $out])->assertExitCode(1);

        expect(is_file($out))->toBeFalse();
    } finally {
        @unlink($out);
    }
});

it('runs the commands that owe no refusal', function (string $name): void {
    [$class, $refuses, $arguments] = unreadConfigRefusalRows()[$name];

    arrangeUnmigrated();
    bindStubEngine();

    test()->artisan($class, $arguments)
        ->doesntExpectOutputToContain('config.not-migrated')
        ->assertExitCode(0);
})->with(array_keys(array_filter(unreadConfigRefusalRows(), static fn (array $row): bool => ! $row[1])));

it('says nothing about a migrated application', function (): void {
    // The refusal's firing population is the unmigrated shape and nothing else: the suite's own
    // configuration — the shipped `docuccino.yaml` beside the shipped framework config — is silent.
    bindStubEngine();

    test()->artisan('docuccino:validate')
        ->doesntExpectOutputToContain('config.not-migrated')
        ->run();
});

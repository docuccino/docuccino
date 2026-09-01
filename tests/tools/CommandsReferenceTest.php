<?php

declare(strict_types=1);

use Docuccino\Laravel\DocuccinoServiceProvider;
use Illuminate\Container\Container;
use Spatie\LaravelPackageTools\Package;

/*
 * The guard behind the artisan command lists. Two pages enumerate the commands by name — the reference
 * itself and the getting-started card — and a command has already shipped missing from both while the
 * suite stayed green. The provider's registration is the source of truth: a command added there fails
 * here until every page that lists them says so.
 */

/** Every command the provider registers, as its class name against its declared $signature. */
function registeredCommandSignatures(): array
{
    $package = new Package;
    (new DocuccinoServiceProvider(new Container))->configurePackage($package);

    $signatures = [];
    foreach ($package->commands as $class) {
        /** @var class-string $class */
        $signature = (new ReflectionClass($class))->getDefaultProperties()['signature'] ?? '';

        expect($signature)->toBeString()->not->toBe('', $class.' declares no $signature');

        $signatures[$class] = (string) $signature;
    }

    return $signatures;
}

/** Every command the provider registers, as the artisan name its signature declares. */
function registeredCommandNames(): array
{
    $package = new Package;
    (new DocuccinoServiceProvider(new Container))->configurePackage($package);

    $names = [];
    foreach ($package->commands as $class) {
        /** @var class-string $class */
        $signature = (new ReflectionClass($class))->getDefaultProperties()['signature'] ?? '';

        expect($signature)->toBeString()->not->toBe('', $class.' declares no $signature');

        preg_match('/^\s*(\S+)/', (string) $signature, $matches);
        $names[] = $matches[1];
    }

    sort($names);

    return $names;
}

/** The command classes that exist on disk, registered or not. */
function shippedCommandClasses(): array
{
    $classes = [];
    foreach (glob(dirname(__DIR__, 2).'/php/laravel/src/Commands/*Command.php') ?: [] as $file) {
        $classes[] = 'Docuccino\\Laravel\\Commands\\'.basename($file, '.php');
    }
    sort($classes);

    return $classes;
}

function commandsReferencePage(): string
{
    return (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/reference/commands.md',
    );
}

function gettingStartedPage(): string
{
    return (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/getting-started/index.mdx',
    );
}

it('registers every command class that ships, and nothing that does not', function (): void {
    $package = new Package;
    (new DocuccinoServiceProvider(new Container))->configurePackage($package);

    $registered = $package->commands;
    sort($registered);

    expect($registered)->toBe(shippedCommandClasses());
});

it('reads a plausible number of commands, and reads only commands', function (): void {
    // A registration array that stopped being readable would make every assertion below vacuous.
    $names = registeredCommandNames();

    expect(count($names))->toBeGreaterThanOrEqual(8)
        ->and($names)->toContain('docuccino:export', 'docuccino:diff', 'docuccino:coverage')
        ->and(array_filter($names, static fn (string $n): bool => ! str_starts_with($n, 'docuccino:')))->toBe([]);
});

it('gives every registered command a section on the reference page', function (): void {
    $page = commandsReferencePage();

    $missing = array_values(array_filter(
        registeredCommandNames(),
        static fn (string $name): bool => ! str_contains($page, '## `'.$name.'`'),
    ));

    expect($missing)->toBe([], 'commands the provider registers with no section of their own');
});

it('gives the reference page no section for a command nothing registers', function (): void {
    // `[\w-]`, not `\w`: a hyphenated name is idiomatic in Laravel's own command set and `\w` alone read
    // one as no heading at all, which would have reported a documented command as undocumented.
    preg_match_all('/^## `(docuccino:[\w-]+)`$/m', commandsReferencePage(), $matches);

    $documented = $matches[1];
    sort($documented);

    expect($documented)->not->toBeEmpty()
        ->and($documented)->toBe(registeredCommandNames());
});

it('names every registered command in the getting-started card', function (): void {
    // The card is prose rather than a table, so this reads the names out of it wherever they sit — what
    // matters is that a reader arriving from the install page is told the command exists at all.
    $card = gettingStartedPage();

    $missing = array_values(array_filter(
        registeredCommandNames(),
        static fn (string $name): bool => ! str_contains($card, '`'.$name.'`'),
    ));

    expect($missing)->toBe([], 'commands missing from the getting-started card');
});

it('names every registered command in the reference page front matter', function (): void {
    // The description line is the page's own summary of what it covers, and it is the half a reader sees
    // in search results — a command absent from it is a command they never learn about.
    $description = '';
    if (preg_match('/^description: (.+)$/m', commandsReferencePage(), $matches) === 1) {
        $description = $matches[1];
    }

    expect($description)->not->toBe('');

    $missing = array_values(array_filter(
        registeredCommandNames(),
        static fn (string $name): bool => ! str_contains($description, substr($name, strlen('docuccino:'))),
    ));

    expect($missing)->toBe([], 'commands missing from the page description');
});

/**
 * The fenced signature block the reference page prints for each command, keyed by artisan name: a block
 * whose first line is the command's own name is the page reproducing its signature.
 */
function commandSignatureBlocks(): array
{
    preg_match_all('/^```\n(docuccino:[\w-]+)\n(.*?)^```$/ms', commandsReferencePage(), $matches, PREG_SET_ORDER);

    $blocks = [];
    foreach ($matches as $match) {
        $blocks[$match[1]] = $match[2];
    }

    return $blocks;
}

it('prints every registered command’s signature on the reference page, argument for argument', function (): void {
    // The page reproduces each signature verbatim in a block of its own, and a signature nobody checks is
    // a promise to remember: an option added, renamed or REDESCRIBED drifts from the command it documents
    // with the whole suite green. Read the lines off the command, and the page has to carry them.
    $blocks = commandSignatureBlocks();

    $missing = [];
    $counted = 0;
    foreach (registeredCommandSignatures() as $class => $signature) {
        $lines = preg_split('/\r?\n/', trim($signature)) ?: [];
        $block = $blocks[trim($lines[0])] ?? null;

        if ($block === null) {
            $missing[] = $class.': no signature block at all';

            continue;
        }

        foreach (array_slice($lines, 1) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $counted++;

            if (! str_contains($block, $line)) {
                $missing[] = $class.': '.$line;
            }
        }
    }

    // A signature reader that stopped seeing lines would make the assertion above vacuous.
    expect($counted)->toBeGreaterThan(20)
        ->and($missing)->toBe([], 'signature lines the reference page does not print');
});

it('prints no signature line the reference page invented for itself', function (): void {
    // The other direction: an option removed from a command has to leave the page too, or the page
    // documents a flag artisan will refuse.
    $declared = [];
    foreach (registeredCommandSignatures() as $signature) {
        foreach (preg_split('/\r?\n/', trim($signature)) ?: [] as $line) {
            $declared[trim($line)] = true;
        }
    }

    $printed = [];
    foreach (commandSignatureBlocks() as $block) {
        foreach (preg_split('/\r?\n/', trim($block)) ?: [] as $line) {
            if (trim($line) !== '') {
                $printed[] = trim($line);
            }
        }
    }

    expect($printed)->not->toBeEmpty()
        ->and(array_values(array_filter($printed, static fn (string $line): bool => ! isset($declared[$line]))))
        ->toBe([], 'signature lines on the page that no command declares');
});

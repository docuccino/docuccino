<?php

declare(strict_types=1);

/*
 * The guard behind the adapter's package declarations. An integration gates on `class_exists` and then
 * emits that package's own grammar, so a package named nowhere in `composer.json` leaves a reader no
 * way to learn it is understood, or which major was tested — and `composer validate` never notices,
 * because nothing is required.
 *
 * The set is derived rather than listed: class-name string literals under `php/laravel/src` — how an
 * integration names a package it deliberately does not depend on — resolved through reflection to the
 * vendor directory that ships them.
 */

/**
 * The vendor packages the adapter's source names, each mapped to one class that named it.
 *
 * @return array<string, string>
 */
function integrationVendorPackages(): array
{
    // These arrive with the framework the adapter already requires (`illuminate/*` brings
    // laravel/framework, which brings the Symfony HTTP components), so they need no line of their own.
    $frameworkProvided = ['laravel/framework', 'symfony/'];

    $literals = [];
    /** @var iterable<SplFileInfo> $files */
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/php/laravel/src')),
        '/\.php$/',
    );

    foreach ($files as $file) {
        foreach (PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $token) {
            if (! $token->is(T_CONSTANT_ENCAPSED_STRING)) {
                continue;
            }

            $literal = stripslashes(trim($token->text, "'\""));

            if (preg_match('/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)+$/', $literal) === 1) {
                $literals[$literal] = true;
            }
        }
    }

    $packages = [];

    foreach (array_keys($literals) as $name) {
        if (! class_exists($name) && ! interface_exists($name) && ! trait_exists($name) && ! enum_exists($name)) {
            continue;
        }

        $declaredIn = (new ReflectionClass($name))->getFileName();

        // Only vendor code answers the question; the sibling packages resolve to their path repository.
        if ($declaredIn === false || preg_match('#/vendor/([^/]+/[^/]+)/#', $declaredIn, $matches) !== 1) {
            continue;
        }

        foreach ($frameworkProvided as $prefix) {
            if (str_starts_with($matches[1], $prefix)) {
                continue 2;
            }
        }

        $packages[$matches[1]] ??= $name;
    }

    ksort($packages);

    return $packages;
}

it('declares every vendor package the adapter names, and versions it on the package page', function (): void {
    $packages = integrationVendorPackages();

    /** @var array{require: array<string, string>, suggest: array<string, string>} $manifest */
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $declared = array_merge($manifest['require'], $manifest['suggest']);

    $page = (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/packages/index.mdx',
    );

    $undeclared = [];
    $unversioned = [];

    foreach ($packages as $package => $namedBy) {
        if (! array_key_exists($package, $declared)) {
            $undeclared[] = $package.' (named by '.$namedBy.')';
        }

        if (! str_contains($page, '[`'.$package.'`]')) {
            $unversioned[] = $package;
        }
    }

    expect($undeclared)->toBe([], 'named by php/laravel/src but declared in no require or suggest: '.implode(', ', $undeclared))
        ->and($unversioned)->toBe([], 'absent from the package page\'s version table: '.implode(', ', $unversioned));
});

it('resolves a plausible number of packages, and only the ones an integration targets', function (): void {
    // A scan that stopped resolving class names would pass the test above with nothing to check. The
    // floor sits at the eight the built-in integrations target today; a ninth integration raises it.
    $packages = integrationVendorPackages();

    expect(count($packages))->toBeGreaterThanOrEqual(8)
        ->and($packages)->toHaveKeys([
            'laravel/passport',
            'laravel/sanctum',
            'lorisleiva/laravel-actions',
            'spatie/laravel-data',
            'spatie/laravel-json-api-paginate',
            'spatie/laravel-permission',
            'spatie/laravel-query-builder',
            'timacdonald/json-api',
        ])
        // Named all over the adapter, and shipped by the framework it already requires.
        ->and($packages)->not->toHaveKey('laravel/framework');
});

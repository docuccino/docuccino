<?php

declare(strict_types=1);

use Docuccino\Laravel\Registry\IntegrationToggles;

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
 * The vendor packages the adapter's source names, as `package => [the class that named it, whether an
 * integration is what names it]`.
 *
 * Both spellings, because an integration names a package it deliberately does not depend on either way:
 * a class-name STRING, which is how a `class_exists` probe writes it, and a class NAME resolved through
 * the file's own imports, which is how `Vendor\Thing::class` and an aliased import write it — a class
 * constant loads nothing, so it is just as safe for an absent package and just as invisible to a reader
 * that only knew about strings.
 *
 * Left out, and stated rather than assumed: a package that arrives with something the adapter already
 * requires. The framework brings `laravel/framework` and the Symfony components; `docuccino/core` brings
 * the parser and the YAML reader, and hands them back across its own public surface, so the adapter
 * naming them is core's contract being used rather than a package being detected. Both lists are READ
 * from the manifests, not typed here.
 *
 * @return array<string, array{namedBy: string, byIntegration: bool}>
 */
function adapterVendorPackages(): array
{
    $root = dirname(__DIR__, 2);

    /** @var array{require: array<string, string>} $core */
    $core = json_decode((string) file_get_contents($root.'/php/core/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    // Prefixes for the framework, whose components are a family; exact names for core's own requires,
    // because a platform entry like `php` prefix-matches half of Packagist.
    $frameworkPrefixes = ['laravel/framework', 'symfony/'];
    $throughCore = array_values(array_filter(array_keys($core['require']), static fn (string $name): bool => str_contains($name, '/')));

    $names = [];
    /** @var iterable<SplFileInfo> $files */
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/php/laravel/src')),
        '/\.php$/',
    );

    foreach ($files as $file) {
        $source = (string) file_get_contents($file->getPathname());
        $integration = str_starts_with(
            substr($file->getPathname(), strlen($root.'/php/laravel/src/')),
            'Integrations/',
        );

        $written = phpReferencedClasses($source);
        foreach (phpStringLiterals($source) as $literal) {
            if (preg_match('/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)+$/', $literal) === 1) {
                $written[] = $literal;
            }
        }

        foreach ($written as $name) {
            $names[$name] = ($names[$name] ?? false) || $integration;
        }
    }

    ksort($names);
    $packages = [];

    foreach ($names as $name => $byIntegration) {
        if (! class_exists($name) && ! interface_exists($name) && ! trait_exists($name) && ! enum_exists($name)) {
            continue;
        }

        $declaredIn = (new ReflectionClass($name))->getFileName();

        // Only vendor code answers the question; the sibling packages resolve to their path repository.
        if ($declaredIn === false || preg_match('#/vendor/([^/]+/[^/]+)/#', $declaredIn, $matches) !== 1) {
            continue;
        }

        if (in_array($matches[1], $throughCore, true)) {
            continue;
        }

        foreach ($frameworkPrefixes as $prefix) {
            if (str_starts_with($matches[1], $prefix)) {
                continue 2;
            }
        }

        $packages[$matches[1]] ??= ['namedBy' => $name, 'byIntegration' => false];
        $packages[$matches[1]]['byIntegration'] = $packages[$matches[1]]['byIntegration'] || $byIntegration;
    }

    ksort($packages);

    return $packages;
}

it('declares every vendor package the adapter names, and versions the ones an integration targets', function (): void {
    $packages = adapterVendorPackages();

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

    foreach ($packages as $package => ['namedBy' => $namedBy, 'byIntegration' => $byIntegration]) {
        if (! array_key_exists($package, $declared)) {
            $undeclared[] = $package.' (named by '.$namedBy.')';
        }

        // The version table speaks for what an INTEGRATION activates on, and that is a property of where
        // the package is named rather than of a list somebody keeps: a package the adapter reaches for
        // outside `Integrations/` — the contract-testing helpers' assertion library — is not something a
        // build detects and documents, and a row for it would tell a reader nothing about their app.
        if ($byIntegration && ! str_contains($page, '[`'.$package.'`]')) {
            $unversioned[] = $package;
        }
    }

    expect($undeclared)->toBe([], 'named by php/laravel/src but declared in no require or suggest: '.implode(', ', $undeclared))
        ->and($unversioned)->toBe([], 'absent from the package page\'s version table: '.implode(', ', $unversioned));
});

/**
 * The `| Key |` rows of the configuration reference's `### integrations` table.
 *
 * @return list<string>
 */
function integrationToggleRows(): array
{
    $page = (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/reference/configuration.md',
    );

    $rows = [];
    $reading = false;

    foreach (explode("\n", $page) as $line) {
        $trimmed = trim($line);

        if (! str_starts_with($trimmed, '|')) {
            $reading = false;

            continue;
        }

        $cells = array_map(trim(...), array_slice(explode('|', $trimmed), 1, -1));

        if (! $reading) {
            // The one three-column `Key` table whose middle column names a package: the integrations
            // table. Matching on the header keeps this off the other `Key` tables on the page.
            $reading = ($cells[0] ?? '') === 'Key' && ($cells[1] ?? '') === 'Package / source';

            continue;
        }

        if (preg_match('/^`([A-Za-z0-9_]+)`$/', $cells[0] ?? '', $matches) === 1) {
            $rows[$matches[1]] = true;
        }
    }

    $rows = array_keys($rows);
    sort($rows);

    return $rows;
}

it('tables every toggleable integration the adapter ships', function (): void {
    // The prose above that table used to count the bags in a word, on this page and on the package page
    // both — a number that goes stale in two places at once and reads as authoritative in neither. The
    // count is gone from the prose; what a reader actually needs is that the ROWS are all of them, which
    // only the set the build reads can say.
    $keys = array_keys(IntegrationToggles::descriptors());
    sort($keys);

    expect(integrationToggleRows())->toBe($keys)
        // Anti-vacuity: a header that moved would leave both sides empty and agreeing.
        ->and(count($keys))->toBeGreaterThanOrEqual(11);
});

it('states no bag count the next integration would falsify', function (): void {
    // Both pages carried the same written-out number. Neither needs one — the table is right there, and
    // the package page links to it — so this holds the prose to carrying none.
    foreach (['laravel/reference/configuration.md', 'laravel/packages/index.mdx'] as $page) {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/website/src/content/docs/'.$page);

        expect($body)->not->toMatch('/\b(?:eleven|twelve|thirteen|\d+)\s+toggleable\b/i');
    }
});

it('resolves a plausible number of packages, and only the ones an integration targets', function (): void {
    // A scan that stopped resolving class names would pass the tests above with nothing to check. The
    // floor sits at the eight the built-in integrations target today; a ninth integration raises it.
    $packages = adapterVendorPackages();
    $targets = array_keys(array_filter($packages, static fn (array $row): bool => $row['byIntegration']));
    sort($targets);

    expect($targets)->toBe([
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
        ->and($packages)->not->toHaveKey('laravel/framework')
        // …and reached through core, which hands its nodes back across its own public surface.
        ->and($packages)->not->toHaveKey('nikic/php-parser')
        // The union is wider than the integrations, and a member outside the eight carries a row here
        // rather than falling in the gap: the contract-testing helpers name their assertion library and
        // the provider names the package-tools base class. Both are declared, and neither is something a
        // build detects in somebody's application, so neither owes a version row.
        ->and(array_keys(array_diff_key($packages, array_flip($targets))))->toBe([
            'phpunit/phpunit',
            'spatie/laravel-package-tools',
        ]);
});

it('sees a package named only as a class constant, which the string reader could not', function (): void {
    // The blind spot this reader used to have, written out: `Vendor\Thing::class` loads nothing, so it is
    // exactly as safe for an absent package as the string probe and exactly as much a declaration that
    // the adapter understands that package — and it appears in no string literal at all.
    $source = <<<'PHP'
    <?php

    namespace Probe;

    use Spatie\QueryBuilder\QueryBuilder;
    use Spatie\QueryBuilder\AllowedFilter as Filter;

    final class Names
    {
        public function run(): array
        {
            return [QueryBuilder::class, Filter::class, \Spatie\QueryBuilder\AllowedSort::class];
        }
    }
    PHP;

    expect(phpStringLiterals($source))->not->toContain('Spatie\QueryBuilder\QueryBuilder')
        ->and(phpReferencedClasses($source))
        ->toContain('Spatie\QueryBuilder\QueryBuilder')
        ->toContain('Spatie\QueryBuilder\AllowedFilter')
        ->toContain('Spatie\QueryBuilder\AllowedSort');

    // …and the string spelling the reader always understood still answers, so the row above is about the
    // half that was added and not about a reader that replaced one blind spot with another.
    expect(phpStringLiterals("<?php\n\$x = 'Spatie\\\\QueryBuilder\\\\QueryBuilder';\n"))
        ->toBe(['Spatie\QueryBuilder\QueryBuilder']);
});

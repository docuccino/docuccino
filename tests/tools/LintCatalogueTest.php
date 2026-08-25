<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Registry\DefaultExtensions;

require_once dirname(__DIR__, 2).'/tools/config-reference-sync.php';

/*
 * The guard behind the lint catalogue. A lint that exists and is registered nowhere runs for nobody:
 * its tests pass, its config bag reads, and no build ever calls it. The set itself is read off the
 * source tree by `shippedLints()` in tests/Pest.php, which the transformer-order test reads too — a
 * list of lints spelled out by hand proves the rows it lists rather than the lints there are.
 */

it('registers every lint core ships in the default extension set', function (): void {
    $registered = array_map(
        static fn (object|string $extension): string => is_object($extension) ? $extension::class : $extension,
        DefaultExtensions::all(new DocumentConfig('default', [])),
    );

    $unregistered = array_values(array_diff(shippedLints(), $registered));

    expect($unregistered)->toBe([], 'lints under php/core/src/Lint that no document registers: '.implode(', ', $unregistered));
});

it('reads a plausible number of lints, and reads only lints', function (): void {
    // A glob that stopped matching would make the check above vacuous, and a discriminator that started
    // counting the options objects beside them would make it wrong.
    $lints = shippedLints();

    expect(count($lints))->toBeGreaterThanOrEqual(6)
        ->and($lints)->toContain('Docuccino\Core\Lint\SensitiveFieldLint', 'Docuccino\Core\Lint\VacuousUnionLint', 'Docuccino\Core\Lint\UnpinnedRedirectLint')
        ->and($lints)->not->toContain('Docuccino\Core\Lint\SensitiveFieldLintOptions', 'Docuccino\Core\Lint\LintOperation');
});

/**
 * The rule keys the shipped config declares under `lint`, commented options included — the config
 * surface IS the set here, since a key the code reads has to appear there and `ConfigReferenceSyncTest`
 * holds it to that in both directions.
 *
 * @return list<string>
 */
function lintConfigRuleKeys(): array
{
    $declared = config_reference_declared_keys(
        (string) file_get_contents(dirname(__DIR__, 2).'/php/laravel/config/docuccino.php'),
    );

    $keys = [];

    foreach ($declared as $path) {
        if (preg_match('/^lint\.([A-Za-z0-9_]+)$/', $path, $matches) === 1) {
            $keys[$matches[1]] = true;
        }
    }

    $keys = array_keys($keys);
    sort($keys);

    return $keys;
}

/**
 * The rule names the configuration reference's own table lists. Its first column is `Rule` rather than
 * `Key`, so the generic table reader does not see it — and that is exactly why it drifted.
 *
 * @return list<string>
 */
function lintReferenceRuleRows(): array
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

        $cell = trim(explode('|', $trimmed)[1] ?? '');

        if (! $reading) {
            $reading = $cell === 'Rule';

            continue;
        }

        if (preg_match('/^`([A-Za-z0-9_]+)`$/', $cell, $matches) === 1) {
            $rows[$matches[1]] = true;
        }
    }

    $rows = array_keys($rows);
    sort($rows);

    return $rows;
}

it('gives every shipped lint a config bag and a row in the reference table', function (): void {
    // The lint catalogue had no guard of its own: the config-reference sync accepts a key documented in
    // the section's PHP block OR in a table, so a rule could lose its ROW — the part of the page a reader
    // scans — with everything green. This reads the two sets that must agree and the count they must
    // both agree with.
    $keys = lintConfigRuleKeys();
    $rows = lintReferenceRuleRows();

    expect($rows)->toBe($keys)
        // One bag per lint. A lint added without a config key has nothing to turn it off with, and one
        // key without a lint switches nothing.
        ->and(count($keys))->toBe(count(shippedLints()))
        // Anti-vacuity: two readers that both stopped seeing their shapes would agree on nothing.
        ->and(count($rows))->toBeGreaterThanOrEqual(7);
});

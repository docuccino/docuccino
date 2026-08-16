<?php

declare(strict_types=1);

/*
 * Per-package line-coverage gate.
 *
 * Sums `statements` / `coveredstatements` per `php/<pkg>/src/` out of a clover report (the method
 * documented in docs/testing.md) and fails when a package is under its floor. Per-package rather than one
 * global `--min=N`, because `inference-phpstan`'s real path executes in a subprocess pcov cannot observe:
 * every engine feature dilutes a GLOBAL ratio even as genuine in-process coverage rises, which puts the
 * whole gate one refactor from red for reasons unrelated to test quality. The engine package carries its
 * own honest low floor instead, and the fully-measurable packages carry high ones.
 *
 * Floors are HONEST measured-now values (the measured percentage rounded DOWN to an integer), never
 * aspirations — see docs/testing.md §"The CI coverage gate & ratchet policy" for the ratchet rules.
 *
 * Usage:
 *   vendor/bin/pest --coverage-clover=build/clover.xml --exclude-group=fixture
 *   php tools/coverage-floors.php build/clover.xml
 */

/**
 * Per-package floors, keyed by the `php/<key>/src/` path segment.
 *
 * `attributes` has no entry: it is dep-free attribute classes with no branching logic and is not in the
 * coverage `<source>` set, so it contributes no statements to measure.
 */
const FLOORS = [
    // Fully in-process-measurable: UIR model, canonicalizer, identities, drafts, emitters, diff, the
    // phpdoc type grammar. Measured 95.29% (5113/5366).
    'core' => 95,
    // Fully in-process-measurable: provider, registry, pipeline, commands, Integrations/.
    // Measured 93.01% (5790/6225).
    'laravel' => 93,
    // Deliberately LOW and not comparable to the others: this package's real analysis runs inside a
    // separate PHP subprocess (see docs/testing.md §"Why the coverage job excludes the fixture group"),
    // which pcov cannot instrument either way. Its behavioural proof is the `fixture` group, not this
    // number — read the figure as "mostly proven out-of-process", never as "untested".
    // The split that produces it: what merely parses source runs well above the package average —
    // `ConstantFolder` is pure php-parser over parsed source, so it unit-tests in-process (41/43) — while
    // the `Tracer` wiring around it is Scope-driven and pcov-invisible either way (0/92). Raising this
    // floor means moving more of the package into the first half; docs/testing.md records each move.
    // Measured 39.28% (665/1693).
    'inference-phpstan' => 39,
];

$report = $argv[1] ?? 'build/clover.xml';

if (! is_file($report)) {
    fwrite(STDERR, sprintf("coverage-floors: clover report not found at %s\n", $report));
    exit(1);
}

$xml = simplexml_load_file($report);
if ($xml === false) {
    fwrite(STDERR, sprintf("coverage-floors: could not parse %s\n", $report));
    exit(1);
}

/** @var array<string, array{statements: int, covered: int}> $totals */
$totals = [];
foreach (array_keys(FLOORS) as $package) {
    $totals[$package] = ['statements' => 0, 'covered' => 0];
}

foreach ($xml->xpath('//file') ?: [] as $file) {
    $path = (string) $file['name'];
    foreach (array_keys(FLOORS) as $package) {
        if (! str_contains($path, '/php/'.$package.'/src/')) {
            continue;
        }
        $metrics = $file->metrics;
        if ($metrics === null) {
            continue;
        }
        $totals[$package]['statements'] += (int) $metrics['statements'];
        $totals[$package]['covered'] += (int) $metrics['coveredstatements'];
        break;
    }
}

$failed = false;
foreach (FLOORS as $package => $floor) {
    $statements = $totals[$package]['statements'];
    if ($statements === 0) {
        fwrite(STDERR, sprintf("coverage-floors: %s contributed no statements — is the report complete?\n", $package));
        $failed = true;

        continue;
    }

    $percent = 100 * $totals[$package]['covered'] / $statements;
    $ok = $percent >= $floor;
    $failed = $failed || ! $ok;

    printf(
        "%s %-18s %6.2f%% (%d/%d)  floor %d%%\n",
        $ok ? 'PASS' : 'FAIL',
        $package,
        $percent,
        $totals[$package]['covered'],
        $statements,
        $floor,
    );
}

if ($failed) {
    fwrite(STDERR, "\ncoverage-floors: a package is below its floor. Raise coverage, or (only with a documented justification — docs/testing.md) lower the floor.\n");
    exit(1);
}

exit(0);

<?php

declare(strict_types=1);

/*
 * Per-package line-coverage gate.
 *
 * Sums `statements` / `coveredstatements` per `php/<pkg>/src/` out of a clover report (the method
 * documented in docs/testing.md) and fails when a package is under its floor. Replaces the single global
 * `pest --min=N`, which was structurally fragile: `inference-phpstan`'s real path executes in a
 * subprocess pcov cannot observe, so every engine feature diluted the GLOBAL ratio even as genuine
 * in-process coverage rose — putting the whole gate one refactor from red for reasons unrelated to test
 * quality. Per-package floors localise that: the engine package carries its own honest low floor and the
 * fully-measurable packages carry high ones.
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
    // Fully in-process-measurable: UIR model, canonicalizer, identities, drafts, emitters, diff,
    // the phpdoc type grammar.
    // Ratcheted 93 → 94 at 94.29% (4527/4801): most of that headroom arrived with the engine-cache
    // deletion, which pinned the result model's serialization contract directly; the type-grammar fix
    // (docblock enums, the `array<K, V>` key rule, the analyser-prefixed `@var`/`@param`/`@property`
    // tags) then landed its 15 new statements fully covered.
    'core' => 94,
    // Fully in-process-measurable: provider, registry, pipeline, commands, Integrations/.
    'laravel' => 91,
    // Deliberately LOW and not comparable to the others: this package's real analysis runs inside a
    // separate PHP subprocess (see docs/testing.md §"Why the coverage job excludes the fixture group"),
    // which pcov cannot instrument either way. Its behavioural proof is the `fixture` group, not this
    // number — read the figure as "mostly proven out-of-process", never as "untested".
    // The split that produces it: what merely parses source runs well above the package average —
    // `ConstantFolder` is pure php-parser over parsed source, so it unit-tests in-process (41/43) — while
    // the `Tracer` wiring around it is Scope-driven and pcov-invisible either way (0/92). Raising this
    // floor means moving more of the package into the first half; docs/testing.md records each move.
    // Dropped 43 → 34 when the worker pool and the engine result cache were deleted: both were in the
    // first half, unit-tested in process at near-full coverage, so removing them took ~300 well-covered
    // statements out of the numerator and left a remainder that is proven out-of-process. 889/2033
    // (43.73%) became 538/1557 (34.55%) without losing a proof — the same denominator move documented
    // for the 41 → 37 drop. Core rose in the same change: the result model's serialization contract,
    // previously covered only as a side effect of the deleted cache tests, is now pinned directly.
    // Ratcheted 34 → 36 at 36.07% (575/1594): the docblock/reflection metadata rules — the promoted
    // `@var` fallback and the class-parameterisation rule, with its refuse-to-refine branches — live in
    // the first half, so every statement they added is unit-tested in process.
    'inference-phpstan' => 36,
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

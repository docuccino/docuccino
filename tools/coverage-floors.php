<?php

declare(strict_types=1);

/*
 * Per-package line-coverage gate, and the record the ratchet policy reads.
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
 * Each entry also carries the `measured` figure the floor was set from, and the run CHECKS it. That is
 * the half nothing was doing: the record is written in three places — this file's entries and comments,
 * the table in docs/testing.md, and the policy prose beside it — and `tests/tools/CoverageRecordTest.php`
 * holds the three to each other, which keeps them consistent and says nothing about whether any of them
 * is TRUE. They were consistent and stale, all three, for the whole of one release. Nothing else in the
 * project measures line coverage, so the run that enforces the floor is the only place the record can be
 * held against reality — and it costs nothing, because it already has both numbers in hand.
 *
 * Usage:
 *   vendor/bin/pest --coverage-clover=build/clover.xml --exclude-group=fixture
 *   php tools/coverage-floors.php build/clover.xml
 */

/**
 * Per-package floor and the measurement it was set from, keyed by the `php/<key>/src/` path segment.
 *
 * `attributes` has no entry: it is dep-free attribute classes with no branching logic and is not in the
 * coverage `<source>` set, so it contributes no statements to measure.
 */
const FLOORS = [
    // Fully in-process-measurable: UIR model, canonicalizer, identities, drafts, emitters, diff, the
    // phpdoc type grammar, the contract checker. Measured 97.47% (13907/14268) — the floor sits at the
    // measured integer, with 0.47pp above it: sixty-seven statements. It was ratcheted 96 → 97 back when
    // the figure was 97.51% over a denominator 1189 statements smaller, dipped to 97.39% as core absorbed
    // work at slightly under its own average, and has come back up without the floor needing to move
    // either time — the ordinary shape for a package this size, and why 97 is where it stays.
    'core' => ['floor' => 97, 'measured' => 97.47],
    // Fully in-process-measurable: provider, registry, pipeline, commands, Integrations/, the
    // contract-testing assertions. Measured 97.05% (13709/14125), and the floor stays at 96 rather than
    // ratcheting to the measured integer. The arithmetic, on the record as the policy asks: 97% of this
    // denominator is 13701.25 statements against the 13709 covered, so a floor of 97 would carry 7.75
    // statements — 0.055pp, an order of magnitude under the 0.47pp `core` carries and the 0.98pp the
    // engine floor below carries. The cost of declining is real and is the other half of the decision: 96
    // leaves 148 statements of room, so a genuine regression smaller than that passes the FLOOR in
    // silence. What answers that is the record check further down, which fires at ten — the floor is no
    // longer the only thing watching this number, which is why it can afford to keep its margin. The
    // margin is worth keeping because the failure this package is exposed to is a denominator change, not
    // a lost proof: deleting 259 fully covered adapter statements drops the ratio under 97 with no change
    // in test quality at all, and deletions of that size have happened here twice. Ratchet to 97 when the
    // figure clears 97.20% — about 28 statements, the order of margin the other two floors carry.
    'laravel' => ['floor' => 96, 'measured' => 97.05],
    // Deliberately LOW and not comparable to the others: this package's real analysis runs inside a
    // separate PHP subprocess (see docs/testing.md §"Why the coverage job excludes the fixture group"),
    // which pcov cannot instrument either way. Its behavioural proof is the `fixture` group, not this
    // number — read the figure as "mostly proven out-of-process", never as "untested".
    // The split that produces it: what merely parses source runs well above the package average —
    // `ConstantFolder` is pure php-parser over parsed source, so it unit-tests in-process (46/47), and so
    // does the trace's file bookkeeping (`TraceFiles`, 10/10) — while the `Tracer` wiring around them is
    // Scope-driven and pcov-invisible either way (0/86). Raising this floor means moving more of the
    // package into the first half; docs/testing.md records each move.
    // The floor RATCHETED 48 → 49 when the figure was 49.09% (1209/2463),
    // and the arithmetic went on the record because the margin was two lines: 49% of that denominator is
    // 1206.87 statements against the 1209 covered, where `laravel` carries about a hundred and forty-eight
    // and `core` about sixty-seven. Taken anyway, for two reasons. The two decisions before it declined at
    // four tenths of a statement (`laravel` at 96.00%) and at one (this package at 49.05%) — a next-line
    // trigger and a one-line one, which is the hair-trigger the policy names — and that one tolerated
    // two. And a floor of 48 leaves 26 statements of room, so a real regression in the measurable half
    // would pass the gate in silence. It then very nearly fired: a fold of the throw analyser's status
    // reads into one recording call deleted a fully covered class and grew a subprocess-only file,
    // 49.30% → 49.11%, moving no golden. Taking that file's PUBLISHED half out into `UnreadStatuses` —
    // records in, notices out, no Scope anywhere near it — is the sixth time the answer has been "close
    // a gap the standards were asking for anyway" (docs/testing.md records each), and it leaves 17.
    // The floor got here as 44 → 46 → 47 → 48 → 49 by keeping the HttpException status
    // read's reflection and its decisions in process behind a source seam, so only the bodies-and-fold
    // adapter is subprocess-only; the hop into the static factory a throw names, the one rule both
    // construction readers share, the response-key range check and the file a folded constant is declared
    // in (`ConstantSource`, native reflection over a declaration) are all written the same way. It has
    // since fired a second time, the same way: reading a status past a callee's `@throws` grew the
    // Scope-driven half by 47 statements, 49.70% → 48.87%, and the answer was again the one the standards
    // were asking for — the rule deciding what a SET of throw readings states came out into
    // `ThrowSiteStatus`, where no scope reaches it and a dataset can drive every way a set fails to speak
    // (18 statements, all covered). Measured 49.98% (1314/2629), about twenty-six statements of margin, so
    // the floor stays at the measured integer rather than ratcheting. Read the same way as before: mostly
    // proven out-of-process, never untested.
    'inference-phpstan' => ['floor' => 49, 'measured' => 49.98],
];

/*
 * How far the run may sit from the recorded figure before the record counts as stale, in STATEMENTS —
 * the unit every floor decision above is argued in, and the one that stays comparable across packages
 * five times apart in size (ten statements is 0.07pp of `laravel` and 0.38pp of the engine).
 *
 * Sized from what the record has actually done, not from what it could do. The drift this check was
 * written for accumulated over 33 source-touching commits and came to 11 statements in `core` and 17 in
 * `laravel`, with the engine unmoved; the two earlier episodes the record's own test names were a `core`
 * figure quoted over a denominator 571 statements out of date, and an engine figure 0.52pp apart —
 * thirteen statements at its denominator. Ten catches all of them and leaves the ordinary case alone: new
 * code lands at close to its package's own rate, so those 33 commits moved these ratios by a tenth of a
 * point between them, and a re-record comes due roughly once a release rather than once a pull request.
 *
 * It also has to be wider than the measurement's own noise, and the noise is not zero: four consecutive
 * runs of one tree put `laravel` at 97.05% three times and 97.07% once, two statements apart in a single
 * file, every other count in the report byte-identical. An exact-equality check would therefore report a
 * stale record at random, which is the failure mode a guard must not have.
 *
 * A run in an environment unlike the coverage job's can also land here — a skipped test covers nothing —
 * so the failure says as much. The record is what CI's coverage job measures, and where a figure flaps
 * the record takes the LOWER of what was observed.
 */
const RECORD_BAND_STATEMENTS = 10.0;

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

/** @var list<string> $stale */
$stale = [];

foreach (FLOORS as $package => $record) {
    $floor = $record['floor'];
    $recorded = $record['measured'];
    $statements = $totals[$package]['statements'];

    if ($statements === 0) {
        fwrite(STDERR, sprintf("coverage-floors: %s contributed no statements — is the report complete?\n", $package));
        $failed = true;

        continue;
    }

    $percent = 100 * $totals[$package]['covered'] / $statements;

    // The drift in the unit the floors are argued in: how many statements' worth of coverage this
    // package has gained or lost against the rate the record claims for it.
    $drift = ($percent - $recorded) * $statements / 100;

    $underFloor = $percent < $floor;
    $offRecord = abs($drift) > RECORD_BAND_STATEMENTS;

    if ($offRecord) {
        $stale[] = $package;
    }

    $failed = $failed || $underFloor || $offRecord;

    printf(
        "%-5s %-18s %6.2f%% %-16s floor %d%%  recorded %.2f%% (%+.1f statements)\n",
        $underFloor ? 'FAIL' : ($offRecord ? 'STALE' : 'PASS'),
        $package,
        $percent,
        sprintf('(%d/%d)', $totals[$package]['covered'], $statements),
        $floor,
        $recorded,
        // The record is quoted to two decimals, which is up to ~0.7 statements of quantisation at these
        // denominators — well inside the band, and the reason a row can read a fraction off and be exact.
        abs($drift) < 0.05 ? 0.0 : $drift,
    );
}

if ($stale !== []) {
    fwrite(STDERR, sprintf(
        "\ncoverage-floors: the recorded figure is more than %.0f statements from this run for: %s.\n".
        "Re-record ALL of them from the percentages above, in one change: the `measured` value and its comment\n".
        "in tools/coverage-floors.php, and the table in docs/testing.md §\"Measured coverage\". Then say whether the\n".
        "floor should ratchet (docs/testing.md §\"The CI coverage gate & ratchet policy\").\n".
        "A run in an environment unlike the coverage job's — a different PHP, a missing extension, a skipped test —\n".
        "lands here too: the record is what CI's coverage job measures.\n",
        RECORD_BAND_STATEMENTS,
        implode(', ', $stale),
    ));
}

if ($failed) {
    fwrite(STDERR, "\ncoverage-floors: a package is below its floor, or its recorded figure no longer describes it. Raise coverage, re-record, or (only with a documented justification — docs/testing.md) lower the floor.\n");
    exit(1);
}

exit(0);

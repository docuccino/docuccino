<?php

declare(strict_types=1);

/*
 * The coverage record is written in three places — the gate's `FLOORS`, the table in docs/testing.md and
 * the prose line beside the ratchet policy — and docs/testing.md says of itself that a record disagreeing
 * with itself is the one artifact the ratchet policy has nothing else to check against. Nothing was
 * asking. It has drifted twice: the floors file once quoted core at 97.51% over a 571-statement smaller
 * package, and one alignment lasted two commits before the engine's figure was 0.52pp apart again.
 *
 * What a test HERE can check is agreement, not truth: whether the figure is still what pcov measures
 * needs a run under a coverage driver, which a unit suite cannot pay for. So truth is checked where the
 * measurement already exists — the gate compares each entry's `measured` against the run it is doing
 * anyway and reports a stale record — and the last two tests below EXECUTE that comparison rather than
 * asserting it happens.
 */

/**
 * The gate's `FLOORS` entries, as `package => [floor, measured, the comment block above it]`.
 *
 * Read off the source rather than by including it, because the tool is a SCRIPT: it runs the gate and
 * exits, so a `require` here would end the worker. Association of a comment to an entry is by position,
 * which is how a reader associates them too.
 *
 * @return array<string, array{int, string, string}>
 */
function gateFloorEntries(): array
{
    $lines = explode("\n", (string) file_get_contents(dirname(__DIR__, 2).'/tools/coverage-floors.php'));
    $entries = [];
    $pending = [];
    $inConst = false;

    foreach ($lines as $line) {
        if ($line === 'const FLOORS = [') {
            $inConst = true;

            continue;
        }
        if (! $inConst) {
            continue;
        }
        if (trim($line) === '];') {
            break;
        }
        if (preg_match("/^\s*'([\w-]+)' => \['floor' => (\d+), 'measured' => (\d+\.\d\d)\],$/", $line, $entry) === 1) {
            $entries[$entry[1]] = [(int) $entry[2], $entry[3], implode("\n", $pending)];
            $pending = [];

            continue;
        }

        $pending[] = $line;
    }

    return $entries;
}

/**
 * @return array<string, int>
 */
function gateFloors(): array
{
    return array_map(static fn (array $entry): int => $entry[0], gateFloorEntries());
}

/**
 * The figure each entry carries as data — the one the gate holds against the run.
 *
 * @return array<string, string>
 */
function gateMeasured(): array
{
    return array_map(static fn (array $entry): string => $entry[1], gateFloorEntries());
}

/**
 * A clover report stating exactly the given per-package totals, written where the gate will read it.
 *
 * One file per package is enough — the gate sums by path segment — so a doctored figure is as easy to
 * hand it as a true one, and no run of the real suite is needed to drive either branch.
 *
 * @param  array<string, array{int, int}>  $totals  package => [covered, statements]
 */
function cloverReportStating(array $totals, string $label): string
{
    $files = '';
    foreach ($totals as $package => [$covered, $statements]) {
        $files .= sprintf(
            '<file name="/repo/php/%s/src/Only.php"><metrics statements="%d" coveredstatements="%d"/></file>',
            $package,
            $statements,
            $covered,
        );
    }

    $path = sys_get_temp_dir().'/docuccino-coverage-record-'.$label.'-'.uniqid().'.xml';
    file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><coverage><project>'.$files.'</project></coverage>');

    return $path;
}

/**
 * Run the gate against a report, both streams captured.
 *
 * @return array{code: int, out: string}
 */
function gateRun(string $report): array
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/tools/coverage-floors.php').' '.escapeshellarg($report).' 2>&1';

    exec($command, $lines, $code);

    return ['code' => $code, 'out' => implode("\n", $lines)];
}

/**
 * A report in which every package sits exactly on its recorded figure, over a denominator big enough
 * that a whole statement is a small fraction of a point.
 *
 * @param  array<string, int>  $adjust  package => statements to take off the covered count
 */
function reportOnTheRecord(array $adjust, string $label): string
{
    $totals = [];
    foreach (gateMeasured() as $package => $measured) {
        $statements = 20000;
        $covered = (int) round($statements * (float) $measured / 100) - ($adjust[$package] ?? 0);
        $totals[$package] = [$covered, $statements];
    }

    return cloverReportStating($totals, $label);
}

/**
 * The measured coverage table in docs/testing.md, as `package => [measured, floor]`. `Overall` and any
 * row with no floor are left out — they are informational and there is nothing to agree with.
 *
 * @return array<string, array{string, int}>
 */
function recordedCoverageTable(): array
{
    $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/testing.md');

    preg_match_all('/^\| `([\w-]+)`\s*\| \*\*(\d+\.\d\d)%\*\* \| (\d+)\s*\|/m', $doc, $rows, PREG_SET_ORDER);

    $table = [];
    foreach ($rows as $row) {
        $table[$row[1]] = [$row[2], (int) $row[3]];
    }

    return $table;
}

it('gates every package the coverage run measures, and names the one it does not', function (): void {
    // The floors are a hand-maintained set, and nothing was reading the source of truth beside them: a
    // package added under `php/` and put in the coverage `<source>` set with no floor is simply not
    // gated, and a floor line the reader stopped matching drops out of the comparison below along with
    // its documentation row, leaving both sides agreeing about a shorter list.
    //
    // So the domain is asserted as a UNION: every package on disk is either measured and gated, or
    // carries a row here saying why it is neither.
    $unmeasured = [
        'attributes' => 'dep-free attribute classes with no branching logic, deliberately outside the coverage <source> set, so it contributes no statements to measure',
    ];

    $xml = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');
    expect($xml)->not->toBeFalse();

    $included = $xml === false ? [] : ($xml->xpath('//source/include/directory') ?? []);

    $measured = [];
    foreach ($included as $directory) {
        if (preg_match('#^php/([\w-]+)/src$#', (string) $directory, $found) === 1) {
            $measured[] = $found[1];
        }
    }
    sort($measured);

    $onDisk = array_values(array_map(
        static fn (string $path): string => basename($path),
        array_filter((array) glob(dirname(__DIR__, 2).'/php/*'), is_dir(...)),
    ));
    sort($onDisk);

    $floors = array_keys(gateFloors());
    sort($floors);

    // A glob or an xpath that stopped matching would make every row below agree over an empty set.
    expect(count($onDisk))->toBeGreaterThanOrEqual(4)
        ->and($measured)->toBe($floors)
        ->and(array_values(array_diff($onDisk, $measured)))->toBe(array_keys($unmeasured));
});

it('has the gate and the coverage table naming the same packages', function (): void {
    // The union against the domain: a floor with no row is a number nobody can audit, and a row with no
    // floor is a package the gate is not watching. Either way the two artifacts have stopped describing
    // one thing.
    $floors = array_keys(gateFloors());
    $rows = array_keys(recordedCoverageTable());
    sort($floors);
    sort($rows);

    expect($rows)->toBe($floors)
        // Well under what the record holds and far above zero: a regex that stopped matching the table
        // would otherwise report perfect agreement over two empty sets.
        ->and(count($floors))->toBeGreaterThan(2);
});

it('quotes one measured figure per floor, and the same one the entry and the table state', function (string $package): void {
    // The half that drifted. Three artifacts state a measured percentage for every gated package and they
    // are read off ONE clover run, so a change that updates any of them alone has misreported a
    // measurement — which is the only thing the ratchet policy has to check a floor against. The entry's
    // `measured` is the copy the gate compares against the run, so the prose either says what it says or
    // one of the two is describing a tree nobody measured.
    $block = gateFloorEntries()[$package][2] ?? '';

    expect(preg_match_all('/Measured (\d+\.\d\d)%/', $block, $quoted))->toBe(1)
        ->and($quoted[1][0])->toBe(recordedCoverageTable()[$package][0])
        ->and($quoted[1][0])->toBe(gateMeasured()[$package]);
})->with(fn () => array_keys(gateFloors()));

it('states one floor per package in the gate, the table and the policy prose alike', function (string $package): void {
    // Three statements of the same integer. The prose line is the one a reader reaches for when deciding
    // whether a ratchet is owed, so it is the one that must never be the stale copy.
    $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/testing.md');

    expect(recordedCoverageTable()[$package][1])->toBe(gateFloors()[$package])
        ->and($doc)->toContain('`'.$package.'` **'.gateFloors()[$package].'**');
})->with(fn () => array_keys(gateFloors()));

it('keeps every floor an honest measured-now value rather than an aspiration', function (string $package): void {
    // The rule stated independently of either artifact: a floor is the measured percentage rounded DOWN,
    // so it may sit at or below the integer part of the figure and never above it. A floor above the
    // measurement is a gate that cannot pass, and a floor set from a number nobody measured is how one
    // ends up two commits from red for reasons unrelated to test quality.
    [$measured, $floor] = recordedCoverageTable()[$package];

    expect($floor)->toBeLessThanOrEqual((int) floor((float) $measured));
})->with(fn () => array_keys(gateFloors()));

it('passes a run that sits on the record, and says so package by package', function (): void {
    // The positive control for everything below: a doctored report is only evidence if the same shape,
    // stating the recorded figures, comes back green. It also pins the reading — one row per gated
    // package, each naming the figure the record claims for it.
    $run = gateRun(reportOnTheRecord([], 'on-record'));

    expect($run['code'])->toBe(0)
        ->and($run['out'])->not->toContain('STALE')
        ->and($run['out'])->not->toContain('FAIL');

    foreach (gateMeasured() as $package => $measured) {
        expect($run['out'])->toContain('PASS  '.$package)
            ->and($run['out'])->toContain('recorded '.$measured.'%');
    }
});

it('holds the recorded figure against the run, and names what to re-record when it has drifted', function (int $lost, bool $stale): void {
    // The guard EXECUTED, on the only axis that matters: a record that no longer describes the tree. The
    // report states every package on its recorded figure over a 20,000-statement denominator, where one
    // statement is exactly one statement of drift — so `lost` is the band itself, either side of it.
    //
    // This is the half `CoverageRecordTest` could not do before: the tests above hold three artifacts to
    // each other, and three artifacts agreeing on a figure nobody measured is precisely how the record
    // spent a release stale while every one of them was green.
    $run = gateRun(reportOnTheRecord(['laravel' => $lost], 'drift-'.$lost));

    expect($run['code'])->toBe($stale ? 1 : 0);

    if (! $stale) {
        expect($run['out'])->toContain('PASS  laravel')
            ->and($run['out'])->not->toContain('STALE');

        return;
    }

    expect($run['out'])->toContain('STALE laravel')
        // A reader of the failure is told which packages, and all three places the record lives.
        ->and($run['out'])->toContain('more than 10 statements from this run for: laravel')
        ->and($run['out'])->toContain('tools/coverage-floors.php')
        ->and($run['out'])->toContain('docs/testing.md');

    // Localised: every package still on its figure passes rather than being dragged in by its neighbour.
    foreach (array_keys(gateMeasured()) as $package) {
        if ($package !== 'laravel') {
            expect($run['out'])->toContain('PASS  '.$package);
        }
    }
})->with([
    'inside the band' => [10, false],
    'one statement past it' => [11, true],
]);

it('reports a package under its floor as a failed gate rather than a stale record', function (): void {
    // The two reasons a row can fail say different things and ask for different answers — raise coverage,
    // or re-record — so a package that is both must read as the more serious of the two.
    $totals = [];
    foreach (gateMeasured() as $package => $measured) {
        $totals[$package] = [$package === 'laravel' ? 9000 : (int) round(20000 * (float) $measured / 100), 20000];
    }

    $run = gateRun(cloverReportStating($totals, 'under-floor'));

    expect($run['code'])->toBe(1)
        ->and($run['out'])->toContain('FAIL  laravel')
        ->and($run['out'])->toContain('below its floor');
});

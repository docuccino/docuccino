<?php

declare(strict_types=1);

/*
 * The coverage record is written in three places — the gate's `FLOORS`, the table in docs/testing.md and
 * the prose line beside the ratchet policy — and docs/testing.md says of itself that a record disagreeing
 * with itself is the one artifact the ratchet policy has nothing else to check against. Nothing was
 * asking. It has drifted twice: the floors file once quoted core at 97.51% over a 571-statement smaller
 * package, and one alignment lasted two commits before the engine's figure was 0.52pp apart again.
 *
 * What a test can check is AGREEMENT, not truth: whether the figure is still what pcov measures needs a
 * `composer test:coverage` run, and that is the ratchet policy's job. Whether the three artifacts say the
 * same thing needs no run at all.
 */

/**
 * The gate's `FLOORS` entries, as `package => [floor, the comment block above it]`.
 *
 * Read off the source rather than by including it, because the tool is a SCRIPT: it runs the gate and
 * exits, so a `require` here would end the worker. Association of a comment to an entry is by position,
 * which is how a reader associates them too.
 *
 * @return array<string, array{int, string}>
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
        if (preg_match("/^\s*'([\w-]+)' => (\d+),$/", $line, $entry) === 1) {
            $entries[$entry[1]] = [(int) $entry[2], implode("\n", $pending)];
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

it('quotes one measured figure per floor, and the same one the table does', function (string $package): void {
    // The half that drifted. Both artifacts state a measured percentage for every gated package, and they
    // are read off ONE clover run — so a change that updates either alone has misreported a measurement,
    // which is the only thing the ratchet policy has to check a floor against.
    $block = gateFloorEntries()[$package][1] ?? '';

    expect(preg_match_all('/Measured (\d+\.\d\d)%/', $block, $quoted))->toBe(1)
        ->and($quoted[1][0])->toBe(recordedCoverageTable()[$package][0]);
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

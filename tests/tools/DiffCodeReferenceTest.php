<?php

declare(strict_types=1);

use Docuccino\Core\Diff\ChangeKind;
use Docuccino\Core\Diff\RefinementMove;
use Docuccino\Core\Diff\SchemaPolarity;

/**
 * The diff's change codes against the page that documents them. Every other hand-maintained catalogue in
 * this repo already has a guard reading its source of truth — diagnostics, attributes, commands, lints,
 * downlevel, export formats, extension contracts, annotation keywords — and the change codes had none,
 * which is how the page came to name `schema.contains-bound-narrowed` and be silent about its sibling.
 *
 * The page is a narrative reference and not a catalogue, so this does not demand it name all fifty codes.
 * It holds the two claims the page DOES make, and the two ways it has already gone wrong: a code named
 * there must be one the comparator can emit, and a code whose meaning is one half of a PAIR must be
 * named with its other half. Naming `-narrowed` and never `-widened` is how a reader concludes the
 * widening is safe — which is exactly the reading `--enforce` used to have.
 */

/** Every `schema.…` string literal the diff source builds a code out of, tokenised rather than grepped. */
function diffCodeLiterals(): array
{
    $literals = [];

    foreach (glob(dirname(__DIR__, 2).'/php/core/src/Diff/*.php') ?: [] as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = substr($token[1], 1, -1);

            if (str_starts_with($value, 'schema.')) {
                $literals[$value] = true;
            }
        }
    }

    return array_keys($literals);
}

/**
 * Every code the diff source can BUILD, which is an upper bound on what it publishes rather than the exact
 * set: a stem is composed at run time and the tokeniser cannot see which arm reaches it, so each stem takes
 * every ending the two enums spell — a `RefinementMove`'s suffix, or a `ChangeKind`. That is the honest
 * shape for the two questions asked of it: whether a documented code is one the source could ever produce,
 * and whether a documented code's sibling exists at all. `Unchanged` is dropped because every caller
 * returns before publishing one — a keyword written out at the value its absence already meant is not a
 * change.
 */
function diffCodesBuildable(): array
{
    $suffixes = [
        ...array_filter(
            array_map(static fn (RefinementMove $move): string => $move->suffix(), RefinementMove::cases()),
            static fn (string $suffix): bool => $suffix !== RefinementMove::Unchanged->suffix(),
        ),
        ...array_map(static fn (ChangeKind $kind): string => $kind->value, ChangeKind::cases()),
    ];

    $codes = [];

    foreach (diffCodeLiterals() as $literal) {
        if (str_ends_with($literal, '-')) {
            foreach ($suffixes as $suffix) {
                $codes[$literal.$suffix] = true;
            }

            continue;
        }

        if ($literal !== 'schema.') {
            $codes[$literal] = true;
        }
    }

    foreach (SchemaPolarity::decided() as $keyword) {
        $stem = SchemaPolarity::rule($keyword)['code'];

        // A null `code` is a position whose absence IS the empty schema, so it publishes no presence
        // change at all and owes no pair.
        if ($stem === null) {
            continue;
        }

        $codes['schema.'.$stem.'-added'] = true;
        $codes['schema.'.$stem.'-removed'] = true;
    }

    $codes = array_keys($codes);
    sort($codes);

    return $codes;
}

/** Every `schema.…` code the reference page names, in backticks. */
function diffCodesDocumented(): array
{
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/website/src/content/docs/laravel/reference/commands.md');

    preg_match_all('/`(schema\.[a-z0-9-]+)`/', $page, $matches);

    $codes = array_values(array_unique($matches[1]));
    sort($codes);

    return $codes;
}

it('names no change code the diff cannot build', function (): void {
    // A dead code on the page is worse than a missing one: a reader greps for it, finds nothing, and
    // concludes the classification changed.
    $buildable = diffCodesBuildable();
    $documented = diffCodesDocumented();

    expect(array_values(array_diff($documented, $buildable)))
        ->toBe([], 'documented but never built: '.implode(', ', array_diff($documented, $buildable)))
        // A scan that matches nothing must fail rather than pass forever.
        ->and(count($buildable))->toBeGreaterThanOrEqual(40)
        ->and(count($documented))->toBeGreaterThanOrEqual(15)
        ->and($buildable)->toContain('schema.refinement-widened', 'schema.contains-bound-widened', 'schema.all-of-branch-removed');
});

it('names both halves of every direction pair it names', function (): void {
    // The failure this exists for. A direction stem publishes `-narrowed` and `-widened` and they take
    // OPPOSITE verdicts on a response, so a page naming one and not the other reads as a statement that
    // the other does not happen. The stems are the source's own composed literals, so a fifth one added
    // there is covered without being named here.
    $documented = diffCodesDocumented();
    $stems = array_values(array_filter(diffCodeLiterals(), static fn (string $literal): bool => str_ends_with($literal, '-')));
    $checked = 0;

    foreach ($stems as $stem) {
        $narrowed = in_array($stem.'narrowed', $documented, true);
        $widened = in_array($stem.'widened', $documented, true);

        if (! $narrowed && ! $widened) {
            continue;
        }

        $checked++;

        expect($narrowed)->toBeTrue($stem.'widened is documented and '.$stem.'narrowed is not')
            ->and($widened)->toBeTrue($stem.'narrowed is documented and '.$stem.'widened is not');
    }

    expect($checked)->toBeGreaterThanOrEqual(3, 'no direction stem is documented at all');
});

it('names both halves of every presence pair it names', function (): void {
    // The same claim one keyword up: `-added` and `-removed` are one decision read in two directions, and
    // four of those pairs had a half that was silently classed safe on a response.
    $documented = diffCodesDocumented();
    $emitted = diffCodesBuildable();
    $checked = 0;

    foreach ($documented as $code) {
        if (preg_match('/^(schema\..+)-(added|removed)$/', $code, $match) !== 1) {
            continue;
        }

        $sibling = $match[1].'-'.($match[2] === 'added' ? 'removed' : 'added');

        if (! in_array($sibling, $emitted, true)) {
            continue;
        }

        $checked++;

        expect(in_array($sibling, $documented, true))->toBeTrue($code.' is documented and '.$sibling.' is not');
    }

    expect($checked)->toBeGreaterThanOrEqual(4, 'no presence pair is documented at all');
});

it('refuses a page that names one half of a pair', function (): void {
    // The two guards above EXECUTED rather than asserted, because a claimed guard that is never made to
    // fail is a claim. Both read a list, so both are handed a list with a half missing.
    $documented = ['schema.contains-bound-narrowed', 'schema.enum-removed'];
    $emitted = diffCodesBuildable();

    $missingDirection = [];
    $missingPresence = [];

    foreach (array_filter(diffCodeLiterals(), static fn (string $literal): bool => str_ends_with($literal, '-')) as $stem) {
        if (in_array($stem.'narrowed', $documented, true) !== in_array($stem.'widened', $documented, true)) {
            $missingDirection[] = $stem;
        }
    }

    foreach ($documented as $code) {
        if (preg_match('/^(schema\..+)-(added|removed)$/', $code, $match) !== 1) {
            continue;
        }

        $sibling = $match[1].'-'.($match[2] === 'added' ? 'removed' : 'added');

        if (in_array($sibling, $emitted, true) && ! in_array($sibling, $documented, true)) {
            $missingPresence[] = $sibling;
        }
    }

    expect($missingDirection)->toBe(['schema.contains-bound-'])
        ->and($missingPresence)->toBe(['schema.enum-added']);
});

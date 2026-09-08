<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * The two conditions held against each other: what the document PUBLISHES under a status nothing
 * stated, and what the build REPORTS about it.
 *
 * A signalled throw with no status hint is one the adapter files under
 * `FrameworkExceptionTable::UNPLACED_STATUS` — a 500 that stands in for a status nobody read. Where
 * the build says nothing about one, the reader is left with a response they cannot explain and no
 * line to go and look at. So every such throw is accounted for here: either the build reported it,
 * or it is written down below as one whose remedy nobody owns.
 *
 * The ledger is what makes this a guard rather than a mirror. It is stated from the CONTRACT — a
 * notice may only address somebody who can act, so silence is owed exactly where the fold gave up on
 * code the reader does not own — and never derived from what the analyser answers, so a new silent
 * row fails this test until a human writes down why nobody could have acted on it.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * The published-but-silent throws, by exception class, with the reason no notice is owed. The one
 * entry is Symfony's own: the status it pins is written in a `vendor/` constructor whose body PHPStan
 * strips, so nothing the reader could edit would have made it readable.
 *
 * @return array<string, string>
 */
function unactionableUnplacedThrows(): array
{
    return [
        'Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException' => 'declared in vendor/, so the status it sets was never read and the edit that would state it is not the reader’s to make',
    ];
}

/**
 * Every action the fixture controller declares, read off the file rather than listed here: a new
 * action whose unplaced status goes unreported has to fail this guard, and it cannot if the guard
 * only knows the actions somebody remembered to add.
 *
 * @return list<string>
 */
function throwActionMethods(): array
{
    $source = (string) file_get_contents(FixtureRunner::path('app/Http/Controllers/ThrowsController.php'));

    preg_match_all('/^    public function (\w+)\(/m', $source, $matches);

    /** @var list<string> $methods */
    $methods = $matches[1];

    // A scan that matched nothing must fail rather than pass forever — the file is real and holds
    // dozens of actions, so a pattern that stopped seeing them is the defect, not an empty corpus.
    expect(count($methods))->toBeGreaterThan(30);

    return $methods;
}

it('reports every unplaced status it publishes, bar the ones nobody can act on', function (): void {
    $analyses = FixtureRunner::analyzeMany(
        'app/Http/Controllers/ThrowsController.php',
        'App\\Http\\Controllers\\ThrowsController',
        throwActionMethods(),
    );

    $unreported = [];
    $reported = 0;
    $unplaced = 0;

    foreach ($analyses as $method => $analysis) {
        /** @var array{throws: list<array<string, mixed>>, diagnostics: list<array<string, mixed>>} $analysis */
        $named = [];
        foreach ($analysis['diagnostics'] as $diagnostic) {
            if (($diagnostic['code'] ?? null) === 'inference.http-exception-status-unread') {
                $named[] = (string) $diagnostic['message'];
            }
        }

        foreach ($analysis['throws'] as $throw) {
            // Only a SIGNAL reaches the document, and the invariant is about what the document
            // publishes: an `internal` throw is carried nowhere, so there is no response for a reader
            // to be unable to explain. One that stops being demoted arrives here as a new row.
            if ($throw['httpStatusHint'] !== null || ($throw['disposition'] ?? null) !== 'signal') {
                continue;
            }

            $unplaced++;
            $fqcn = (string) $throw['exceptionFqcn'];
            $isReported = false;
            foreach ($named as $message) {
                $isReported = $isReported || str_starts_with($message, $fqcn.' ');
            }

            if ($isReported) {
                $reported++;

                continue;
            }

            $unreported[$fqcn] = ((string) $method).' publishes it unreported';
        }
    }

    ksort($unreported);
    $ledger = unactionableUnplacedThrows();
    ksort($ledger);

    // The corpus really contains both halves. Without this the comparison below is satisfied by an
    // analysis that surfaced no throws at all.
    expect($unplaced)->toBeGreaterThan(8)
        ->and($reported)->toBeGreaterThan(6);

    // Exactly the ledger: nothing silent that is not written down, and nothing written down that the
    // build has since started reporting — a stale excuse is how a notice ends up owed and never given.
    expect(array_keys($unreported))->toBe(array_keys($ledger));
})->group('fixture');

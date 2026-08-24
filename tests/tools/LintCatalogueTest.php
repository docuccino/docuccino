<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Registry\DefaultExtensions;

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

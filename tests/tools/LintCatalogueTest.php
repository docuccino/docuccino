<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Laravel\Registry\DefaultExtensions;

/*
 * The guard behind the lint catalogue. A lint that exists and is registered nowhere runs for nobody:
 * its tests pass, its config bag reads, and no build ever calls it — and the transformer-order test
 * cannot see the gap, because it pins the lints it lists rather than the lints there are.
 */

/**
 * The lint rules core ships: everything under `Lint/` that is a document transformer. The neighbours in
 * there are options objects and pure helpers, so implementing the contract is what makes one a rule —
 * no list, and a helper that grows into a lint is caught the day it does.
 *
 * @return list<string>
 */
function shippedLints(): array
{
    $lints = [];

    foreach ((array) glob(dirname(__DIR__, 2).'/php/core/src/Lint/*.php') as $file) {
        $class = 'Docuccino\Core\Lint\\'.basename((string) $file, '.php');

        if ((new ReflectionClass($class))->implementsInterface(DocumentTransformer::class)) {
            $lints[] = $class;
        }
    }

    sort($lints);

    return $lints;
}

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

    expect(count($lints))->toBeGreaterThanOrEqual(5)
        ->and($lints)->toContain('Docuccino\Core\Lint\SensitiveFieldLint', 'Docuccino\Core\Lint\VacuousUnionLint')
        ->and($lints)->not->toContain('Docuccino\Core\Lint\SensitiveFieldLintOptions', 'Docuccino\Core\Lint\LintOperation');
});

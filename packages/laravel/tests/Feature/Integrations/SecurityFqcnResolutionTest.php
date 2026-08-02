<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Passport\PassportIntegration;
use Docuccino\Laravel\Integrations\Sanctum\SanctumDetector;
use Docuccino\Laravel\Integrations\Sanctum\SanctumIntegration;

/**
 * The Sanctum/Passport integrations gate on, and match against, hard-coded framework FQCN strings
 * (registrar `class_exists` guards, the stateful-middleware the detector looks for). A silent typo
 * in any of them would make an integration never register or never detect — invisible to the golden
 * suite (the feature simply never fires). These assert every such string resolves against the
 * installed packages (sanctum + passport are dev requirements), closing that typo risk (arch B8).
 */
it('resolves every registrar guard + matcher FQCN string against the installed packages', function (string $fqcn): void {
    expect(class_exists($fqcn) || interface_exists($fqcn))->toBeTrue("FQCN string does not resolve: {$fqcn}");
})->with([
    'Sanctum registrar guard' => [SanctumIntegration::SANCTUM],
    'Passport registrar guard' => [PassportIntegration::PASSPORT],
    'Sanctum stateful middleware matcher' => [
        (new ReflectionClass(SanctumDetector::class))->getReflectionConstant('STATEFUL_MIDDLEWARE')->getValue(),
    ],
]);

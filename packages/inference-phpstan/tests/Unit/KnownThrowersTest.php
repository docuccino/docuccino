<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Inference\PhpStan\Throwing\KnownThrower;
use Docuccino\Inference\PhpStan\Throwing\KnownThrowers;

/**
 * The status table is single-sourced on the registry: `statusForExceptionFqcn()`
 * (consulted by the throw analyzer's layer-1 enrichment) and `forMethod()`
 * (layer-2 rescue) draw from the SAME registered throwers, so a user's
 * `withMethod()` extension enriches both layers rather than only the rescue path.
 */
it('exposes the default throwers as an exception-FQCN status source', function (): void {
    $registry = KnownThrowers::default();

    expect($registry->statusForExceptionFqcn(KnownThrowers::VALIDATION_EXCEPTION))->toBe(422)
        ->and($registry->statusForExceptionFqcn(KnownThrowers::AUTHORIZATION_EXCEPTION))->toBe(403)
        ->and($registry->statusForExceptionFqcn(KnownThrowers::MODEL_NOT_FOUND_EXCEPTION))->toBe(404)
        // abort's HttpException folds its status from a call argument, so it has
        // no fixed status and must NOT appear as an enrichable exception FQCN.
        ->and($registry->statusForExceptionFqcn(KnownThrowers::HTTP_EXCEPTION))->toBeNull()
        ->and($registry->statusForExceptionFqcn('App\\Exceptions\\Nope'))->toBeNull();
});

it('lets a custom withMethod() thrower enrich BOTH layers from one registration', function (): void {
    $custom = 'App\\Exceptions\\TeapotException';
    $registry = KnownThrowers::default()->withMethod(
        'brew',
        KnownThrower::withStatus($custom, 418),
    );

    // Layer 1 (explicit-throw enrichment): the exception FQCN now resolves to 418.
    expect($registry->statusForExceptionFqcn($custom))->toBe(418)
        ->and($registry->knownStatuses())->toHaveKey($custom);

    // Layer 2 (implicit-forwarder rescue): the same registration surfaces by name.
    $thrower = $registry->forMethod('brew');
    expect($thrower)->not->toBeNull()
        ->and($thrower->exceptionFqcn)->toBe($custom)
        ->and($thrower->fixedStatus)->toBe(418);
});

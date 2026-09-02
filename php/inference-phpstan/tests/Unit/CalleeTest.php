<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Trace\Callee;

/**
 * The resolved call target, whose {@see Callee::key()} is what the descent memoises and cycle-guards on.
 * It names the DECLARING class rather than the receiver's, so one inherited helper is walked once however
 * many subclasses reach it — and two subclasses cannot each spend the budget on the same body.
 */
it('identifies a callee by its declaring class and method', function (): void {
    $callee = new Callee('App\\Exceptions\\ProblemRenderer', 'render', '/app/Exceptions/ProblemRenderer.php');

    expect($callee->key())->toBe('App\\Exceptions\\ProblemRenderer::render')
        ->and((new Callee('App\\Exceptions\\ProblemRenderer', 'render', '/elsewhere.php'))->key())
        ->toBe($callee->key())
        // With no second file given, where the body is written is where the class is.
        ->and($callee->writtenIn())->toBe('/app/Exceptions/ProblemRenderer.php');
});

it('names the file a body was written in apart from the one its class is', function (): void {
    // PHP reports a trait-imported method as the USING class's, so the declaring class's file is not where
    // the body — or the `@throws` on it — is written. Both are dependencies: the harvest comes off one and
    // the decision was written in the other.
    $callee = new Callee(
        'App\\Http\\Controllers\\ProbeController',
        'guard',
        '/app/Http/Controllers/ProbeController.php',
        '/app/Support/Concerns/Guards.php',
    );

    expect($callee->writtenIn())->toBe('/app/Support/Concerns/Guards.php')
        // …and identity is still the declaring class's, so one shared guard is walked once.
        ->and($callee->key())->toBe('App\\Http\\Controllers\\ProbeController::guard');
});

<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Tests\Support\TraitUsingRenderer;
use Docuccino\Inference\PhpStan\Trace\Callee;
use Docuccino\Inference\PhpStan\Trace\CalleeResolver;
use PHPStan\Reflection\ReflectionProvider;

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

it('reads a trace root by the same two files a resolved callee carries', function (): void {
    // The root arrives as a class/method/file rather than as a call to resolve, and a trait-imported
    // action is the same defect as a trait-imported callee: the harvest comes off the class's file and
    // the body that decided is written in the trait's. The provider is never consulted for this.
    $resolver = new CalleeResolver($this->createStub(ReflectionProvider::class));

    $root = $resolver->root(TraitUsingRenderer::class, 'traitDeclared', '/app/TraitUsingRenderer.php');

    expect($root->file)->toBe('/app/TraitUsingRenderer.php')
        ->and(basename($root->writtenIn()))->toBe('DeclaresProblems.php')
        ->and($root->key())->toBe(TraitUsingRenderer::class.'::traitDeclared');
});

it('leaves a trace root it cannot reflect on the file it was given', function (): void {
    // A method reflection cannot name — a stub, a class only the analyser knows — degrades to the file
    // the caller handed over rather than dropping the root's accounting altogether.
    $resolver = new CalleeResolver($this->createStub(ReflectionProvider::class));

    $root = $resolver->root('App\\Nowhere\\Absent', 'handle', '/app/Nowhere/Absent.php');

    expect($root->writtenIn())->toBe('/app/Nowhere/Absent.php');
});

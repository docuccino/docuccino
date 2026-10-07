<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Which of an action's throws become API errors at all, against the real engine: abort status
 * folding, registry enrichment + rescue, bounded descent, `@throws` trust and catch subtraction.
 * What STATUS the surfaced error then carries is {@see ThrowStatusTest}.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('surfaces exactly the expected API errors', function (string $method, array $expected): void {
    sort($expected);

    expect(signalThrows($method))->toBe($expected);
})->with([
    'abort + abort_if, both statuses folded' => ['abortAction', ['HttpException@403', 'HttpException@404']],
    // The same two calls with the status named rather than counted. PHPStan hands throw points the
    // NORMALIZED call, so a named argument already sits in the position the registry indexes — pinned
    // here because the day that stops being true, both statuses vanish without a word.
    'abort + abort_if, statuses named' => ['namedAbortAction', ['HttpException@418', 'HttpException@451']],
    'authorize → 403' => ['authorizeAction', ['AuthorizationException@403']],
    'static findOrFail rescued → 404' => ['findOrFailAction', ['ModelNotFoundException@404']],
    'inline validate → 422' => ['validateAction', ['ValidationException@422']],
    '2-deep descent, no @throws' => ['deepUndeclared', ['OutOfStockException@500', 'RuntimeException@500']],
    '@throws trusted, deeper hidden' => ['deepDeclared', ['OutOfStockException@500']],
    'vendor any-throwable = no API error' => ['anyThrowableNoise', []],
    'caught subtracted, escaping surfaced' => ['tryCatch', ['RuntimeException@500']],
    // The same catch around a call that DECLARES what it throws. The point that survives the catch names no
    // class at all, and a declaring callee is not descended for what it might throw besides — doing so
    // publishes the very exceptions the action turns into a 200.
    'caught across a declaring call' => ['tryCatchDeclared', ['RuntimeException@500']],
    // The catch takes what the callee declares and nothing its closure argument throws: the closure is
    // the action's own code, read whatever became of the call's declared classes.
    'caught declaring call, closure argument escapes' => ['tryCatchDeclaredClosure', ['OutOfStockException@500', 'RuntimeException@500']],
    // …and the catch is in force over the closure all the same: what it takes is not published.
    'caught inside a closure argument' => ['tryCatchClosure', ['RuntimeException@500']],
    // The same catch around a call that declares NOTHING. What it throws is found by descending into it, and
    // the catch in the action's own body is what decides which of those leave: PHP hands a catch everything
    // the try's statements raise that is an instance of a class it names, whether the analyser saw a
    // declaration or not. Read from the source rather than off the analyser's point, which names the residue
    // on one PHPStan minor and plain `Throwable` on the one before it.
    'caught across an undeclared call' => ['caughtUndeclared', ['RuntimeException@500']],
    // A declaring callee is not descended for what it hides, caught or not: what the catch leaves of a
    // `@throws` is the `@throws` minus the catch, which is nothing here, as `deepDeclared` publishes only the
    // declared class. Descending anyway would answer the hidden RuntimeException on PHPStan 2.3, which keeps
    // a point for the residue, and nothing on 2.2, which does not.
    'caught across a declaring call that hides more' => ['caughtDeclaredWithResidue', ['LogicException@500']],
    'caught by a base class of both' => ['caughtUndeclaredByParent', ['LogicException@500']],
    'caught by an interface of both' => ['caughtUndeclaredByInterface', ['LogicException@500']],
    'caught by a multi-catch naming both' => ['caughtUndeclaredMulti', ['LogicException@500']],
    // What a catch does next is its own throw point, read where it is written: a rethrow still leaves, and a
    // translation leaves as the class it translates to.
    'caught and rethrown' => ['caughtUndeclaredRethrown', ['OutOfStockException@500', 'RuntimeException@500']],
    'caught and translated' => ['caughtUndeclaredTranslated', ['LogicException@500', 'RuntimeException@500']],
    'caught by nested tries, one each' => ['caughtUndeclaredNested', ['LogicException@500']],
    // The two shapes that take nothing: a `finally` alone, and a catch of a SUBCLASS of what is thrown — the
    // thrown class is not an instance of it, so the catch may take some instances and the rest still leave.
    'a finally with no catch' => ['undeclaredInFinallyOnly', ['OutOfStockException@500', 'RuntimeException@500']],
    'a catch narrower than the throw' => ['caughtUndeclaredNarrower', ['OutOfStockException@500', 'RuntimeException@500']],
    // The same subtraction one level down, written in the callee's body around a call it descends into.
    'caught inside the descended callee' => ['caughtInsideCallee', ['OutOfStockException@500']],
    // …and across the two other ways a throw reaches the action as a call: a closure the callee runs, and
    // a callee only the registry can read.
    'caught around a closure the callee runs' => ['caughtClosureThrow', ['ExportUnsupportedException@422']],
    'caught around a registry-read call' => ['caughtFindOrFail', ['HttpException@410']],
    // The registry is keyed on a bare method name, so an app's own validate() is exactly where a guess
    // could overrule a truth: the callee is project code we read, so its own exception stands and no
    // ValidationException/422 is invented for it.
    "the app's own validate() keeps its own exception" => ['projectValidate', ['OutOfStockException@500']],
])->group('fixture');

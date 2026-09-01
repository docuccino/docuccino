<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeBranching;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeConstantPinned;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeInherited;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeInheritsPin;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeNoConstructor;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeNoParentCall;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeOverridingFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbePinned;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbePlain;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbePublicDefault;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeTraitFactory;
use Docuccino\Inference\PhpStan\Tests\Support\ParsedBodies;
use Docuccino\Inference\PhpStan\Throwing\ClassBodies;
use Docuccino\Inference\PhpStan\Throwing\HttpExceptionStatus;
use PhpParser\Node;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The status read over REAL exception classes — the hierarchy, the visibility and the defaults are all
 * reflection over the probes, and only the bodies and the fold come from a source. That the analyser hands
 * over the same bodies and folds the same constants is the fixture group's job ({@see ThrowCasesTest});
 * everything the read DECIDES is decided here.
 */
function httpStatuses(): HttpExceptionStatus
{
    return new HttpExceptionStatus(new ParsedBodies);
}

it('reads the status a class states for every one of its instances', function (string $fqcn, ?int $status): void {
    expect(httpStatuses()->pinned($fqcn))->toBe($status);
})->with([
    'a literal reaching the parent' => [ProbePinned::class, 422],
    // Folding is the source's, never hand-rolled here — a class constant reads like the literal beside it.
    'a class constant reaching the parent' => [ProbeConstantPinned::class, 409],
    'a literal two classes up, through a base that adds nothing' => [ProbeInherited::class, 503],
    // A base that pins leaves a subclass only the message to choose, so the pin is the subclass's too.
    'a pin inherited from a base that states it' => [ProbeInheritsPin::class, 410],
    // Symfony's own subclasses are written this way, and used to resolve to 500 like everything else.
    'a vendor subclass pinning its own' => [NotFoundHttpException::class, 404],
    // A private constructor with no factory writing the slot: the default is what every instance carries.
    'a private constructor default' => [ProbeFactory::class, 422],
    // …and every negative, each of which would be a status the code does not state.
    'a factory that writes the slot itself' => [ProbeOverridingFactory::class, null],
    'a PUBLIC constructor default' => [ProbePublicDefault::class, null],
    'factories in a trait, written in another file' => [ProbeTraitFactory::class, null],
    'a constructor choosing its status by branch' => [ProbeBranching::class, null],
    'a constructor that never reaches its parent' => [ProbeNoParentCall::class, null],
    'no constructor at all — the status is an argument' => [ProbeNoConstructor::class, null],
    'HttpException itself' => [HttpException::class, null],
    'a class that is not an HttpException' => [ProbePlain::class, null],
    'a name no class answers to' => ['App\\Nope\\NoSuchException', null],
]);

it('names the slot a class forwards its status through, where it forwards one', function (string $fqcn, ?int $slot): void {
    expect(httpStatuses()->statusParameter($fqcn))->toBe($slot);
})->with([
    // No class below HttpException adds a constructor, so its own runs and argument 0 IS the status.
    'no constructor at all' => [ProbeNoConstructor::class, 0],
    'HttpException itself' => [HttpException::class, 0],
    // The forwarded parameter, named so a `throw new X(…, 409)` can still be folded at its site.
    'a public constructor forwarding its only parameter' => [ProbePublicDefault::class, 0],
    'a factory class forwarding a later parameter' => [ProbeFactory::class, 1],
    // Nothing to forward: the status is pinned, or nothing reached the parent at all.
    'a class that pins a literal' => [ProbePinned::class, null],
    'a constructor choosing by branch' => [ProbeBranching::class, null],
    'a class that is not an HttpException' => [ProbePlain::class, null],
]);

it('reports the constructor slot a construction would fill, and the default it would take', function (string $fqcn, int $slot, array $names, ?int $default): void {
    expect(httpStatuses()->constructorSlot($fqcn, $slot))->toBe(['names' => $names, 'default' => $default]);
})->with([
    // A class of its own: the slot the factories leave empty carries the value they all take.
    'a defaulted slot' => [ProbeFactory::class, 1, ['fields', 'statusCode'], 422],
    'the slot before it, which has no default' => [ProbeFactory::class, 0, ['fields', 'statusCode'], null],
    // No constructor of its own, so the framework's is what a `new` binds to — status first, no default.
    'the inherited constructor' => [ProbeNoConstructor::class, 0, ['statusCode', 'message', 'previous', 'headers', 'code'], null],
    // A default that is not an integer is not a status, however present it is.
    'a slot defaulting to a string' => [ProbeNoConstructor::class, 1, ['statusCode', 'message', 'previous', 'headers', 'code'], null],
    'a slot past the end of the signature' => [ProbePinned::class, 4, [], null],
    'a name no class answers to' => ['App\\Nope\\NoSuchException', 0, [], null],
]);

it('records every file in the hierarchy, not only the one that declares the constructor today', function (): void {
    // Fragment-cache soundness: adding a constructor to the base changes the answer, so the base's file has
    // to be able to invalidate the routes that throw the subclass.
    $files = httpStatuses()->filesFor(ProbeInherited::class);
    $names = array_map(static fn (string $file): string => basename($file), $files);

    expect($names)->toContain('ProbeInherited.php')
        ->and($names)->toContain('ProbeBase.php')
        ->and($names)->toContain('HttpException.php')
        ->and(httpStatuses()->filesFor(ProbePlain::class))->toBe([]);
});

it('states nothing about a class whose body it cannot read', function (): void {
    // A source with no bodies is what an unparsable or stripped file looks like from here. The class still
    // reflects — it is an HttpException, and it declares a constructor — so this is the branch where the
    // answer has to be "unknown" rather than the default the constructor happens to carry.
    $blind = new class implements ClassBodies
    {
        public function methods(string $file, string $class): array
        {
            return [];
        }

        public function foldInt(string $file, string $class, string $method, Node\Expr $expr): ?int
        {
            return null;
        }
    };

    $statuses = new HttpExceptionStatus($blind);

    expect($statuses->pinned(ProbePinned::class))->toBeNull()
        ->and($statuses->pinned(ProbeFactory::class))->toBeNull()
        ->and($statuses->statusParameter(ProbePinned::class))->toBeNull()
        // The hierarchy is still reflection, so a class adding no constructor still answers.
        ->and($statuses->statusParameter(ProbeNoConstructor::class))->toBe(0);
});

it('answers an HttpException subclass and nothing else', function (string $fqcn, bool $is): void {
    expect(httpStatuses()->isHttpException($fqcn))->toBe($is);
})->with([
    'a subclass' => [ProbePinned::class, true],
    'the class itself' => [HttpException::class, true],
    'a plain exception' => [ProbePlain::class, false],
    'a name no class answers to' => ['App\\Nope\\NoSuchException', false],
]);

<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Inference\PhpStan\Analysis\NarrowingGuard;

/**
 * Which return site a narrowed renderer's thrown type reaches. The rule under test is that a guard reads
 * the same grammar as the runtime test: `&&` requires, `|` alternates, and reading one as the other is
 * how a marker-interface arm answers for a type it never sees.
 */
it('reads a union as alternatives and an intersection as requirements', function (): void {
    expect(NarrowingGuard::ofType(new ClassT(RuntimeException::class)))
        ->toBe([[RuntimeException::class]])
        ->and(NarrowingGuard::ofType(new UnionT([new ClassT(RuntimeException::class), new ClassT(LogicException::class)])))
        ->toBe([[RuntimeException::class], [LogicException::class]])
        ->and(NarrowingGuard::ofType(new IntersectionT([new ClassT(RuntimeException::class), new ClassT(Countable::class)])))
        ->toBe([[RuntimeException::class, Countable::class]])
        // Nothing class-shaped says nothing about the parameter, which is the default branch.
        ->and(NarrowingGuard::ofType(ScalarT::string()))->toBe([]);
});

it('answers whether a narrowed type reaches a guard', function (array $guard, string $narrowTo, bool $reaches): void {
    expect(NarrowingGuard::satisfiedBy($guard, $narrowTo))->toBe($reaches);
})->with([
    'default branch' => [[], OutOfBoundsException::class, true],
    'exact' => [[[RuntimeException::class]], RuntimeException::class, true],
    'subclass' => [[[RuntimeException::class]], OutOfBoundsException::class, true],
    'unrelated' => [[[LogicException::class]], RuntimeException::class, false],
    'either of a union' => [[[LogicException::class], [RuntimeException::class]], RuntimeException::class, true],
    'both of an intersection' => [[[RuntimeException::class, Countable::class]], ArrayIterator::class, false],
    'one of an intersection is not enough' => [[[LogicException::class, Countable::class]], LogicException::class, false],
]);

it('merges two guards that must both hold, and lets an empty one gate nothing', function (): void {
    expect(NarrowingGuard::allOf([['A']], [['B']]))->toBe([['A', 'B']])
        ->and(NarrowingGuard::allOf([['A'], ['B']], [['C']]))->toBe([['A', 'C'], ['B', 'C']])
        // A side carrying no `instanceof` information cannot narrow, so it leaves the other side alone
        // rather than emptying it — an arm gated on `$e instanceof A && $x > 1` is still gated on A.
        ->and(NarrowingGuard::allOf([], [['B']]))->toBe([['B']])
        ->and(NarrowingGuard::allOf([['A']], []))->toBe([['A']])
        ->and(NarrowingGuard::allOf([], []))->toBe([])
        // One class named twice is one requirement.
        ->and(NarrowingGuard::allOf([['A']], [['A']]))->toBe([['A']]);
});

it('tells an arm that names the thrown class from one that only names a base it extends', function (): void {
    // The difference decides whether a later exact arm is shadowed, and whether that is worth reporting.
    expect(NarrowingGuard::namesExactly([[RuntimeException::class]], RuntimeException::class))->toBeTrue()
        ->and(NarrowingGuard::namesExactly([[RuntimeException::class, Countable::class]], Countable::class))->toBeTrue()
        ->and(NarrowingGuard::namesExactly([[RuntimeException::class]], OutOfBoundsException::class))->toBeFalse()
        ->and(NarrowingGuard::namesExactly([], RuntimeException::class))->toBeFalse();
});

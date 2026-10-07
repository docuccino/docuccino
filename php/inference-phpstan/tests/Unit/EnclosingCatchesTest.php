<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Throwing\EnclosingCatches;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/*
 * What a catch takes is PHP's rule: everything raised by the try's own statements. Each snippet marks one
 * call `here()`, and the row says which classes the catches around it name — outermost first, never one whose
 * try the call is not in.
 */
it('names the classes every catch around an offset takes', function (string $code, array $expected): void {
    $source = '<?php '.$code;
    $statements = (new ParserFactory)->createForHostVersion()->parse($source) ?? [];

    $names = EnclosingCatches::around($statements, (int) strpos($source, 'here()'));

    expect(array_map(static fn (Node\Name $name): string => $name->toString(), $names))->toBe($expected);
})->with([
    'no try at all' => ['here();', []],
    'one catch' => ['try { here(); } catch (A $e) {}', ['A']],
    'two catches' => ['try { here(); } catch (A $e) {} catch (B $e) {}', ['A', 'B']],
    'a multi-catch' => ['try { here(); } catch (A|B $e) {}', ['A', 'B']],
    'deep inside the try' => ['try { if ($x) { foreach ($y as $z) { $q = here(); } } } catch (A) {}', ['A']],
    'nested tries, outer first' => ['try { try { here(); } catch (A) {} } catch (B) {}', ['B', 'A']],
    // A throw in a handler leaves past its own try, and reaches only the tries around that one.
    'inside the catch body' => ['try { x(); } catch (A) { here(); }', []],
    'inside the finally' => ['try { x(); } catch (A) {} finally { here(); }', []],
    'inside an inner catch body' => ['try { try { x(); } catch (A) { here(); } } catch (B) {}', ['B']],
    'a finally and no catch' => ['try { here(); } finally { x(); }', []],
    // A catch that can rethrow what it caught lets all of it out, on whichever path; one that throws
    // anything else, or the variable of another catch, still takes what it names.
    'a catch that rethrows' => ['try { here(); } catch (A $e) { log($e); throw $e; }', []],
    'a catch that rethrows on one path' => ['try { here(); } catch (A $e) { if ($x) { throw $e; } }', []],
    'a rethrow beside a catch that keeps' => ['try { here(); } catch (A $e) { throw $e; } catch (B $e) {}', ['B']],
    'a rethrow from a closure in the catch' => ['try { here(); } catch (A $e) { retry(fn () => throw $e); }', []],
    'a catch that translates' => ['try { here(); } catch (A $e) { throw new B(previous: $e); }', ['A']],
    'a catch that throws another variable' => ['try { here(); } catch (A $e) { throw $f; }', ['A']],
    'a catch with no variable' => ['try { here(); } catch (A) { throw $e; }', ['A']],
    // A function or class written in the catch body cannot see the variable: its `$e` is its own.
    'a rethrow inside a nested function' => ['try { here(); } catch (A $e) { function f($e) { throw $e; } }', ['A']],
    'a rethrow inside a nested class' => ['try { here(); } catch (A $e) { $o = new class { function m($e) { throw $e; } }; }', ['A']],
    'after the try' => ['try { x(); } catch (A) {} here();', []],
    // A function's body runs wherever it is called from, so a try around where it is WRITTEN takes nothing.
    'inside a closure in the try' => ['try { $f = function () { here(); }; } catch (A) {}', []],
    'inside an anonymous class in the try' => ['try { $o = new class { function m() { here(); } }; } catch (A) {}', []],
]);

it('reads nothing where the offset is unknown', function (): void {
    $statements = (new ParserFactory)->createForHostVersion()->parse('<?php try { here(); } catch (A) {}') ?? [];

    expect(EnclosingCatches::around($statements, -1))->toBe([]);
});

it('lists the calls and throws a try guards, under the same boundaries', function (): void {
    $statements = (new ParserFactory)->createForHostVersion()->parse(<<<'PHP'
        <?php
        outside();
        throw new Outside;
        try {
            guarded(function () { inClosure(); throw new InClosure; });
            throw new Guarded;
            try { nested(); } catch (A $e) { inInnerCatch(); throw $e; }
        } catch (B) {
            inCatch();
            throw new InCatch;
        } finally {
            inFinally();
        }
        PHP) ?? [];

    expect(array_map(
        static fn (Node\Expr $node): string => match (true) {
            $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name => $node->name->toString(),
            $node instanceof Node\Expr\Throw_ && $node->expr instanceof Node\Expr\New_ && $node->expr->class instanceof Node\Name => 'throw '.$node->expr->class->toString(),
            $node instanceof Node\Expr\Throw_ => 'rethrow',
            $node instanceof Node\Expr\New_ && $node->class instanceof Node\Name => 'new '.$node->class->toString(),
            default => '?',
        },
        EnclosingCatches::guardedIn($statements),
    ))->toBe(['guarded', 'throw Guarded', 'new Guarded', 'nested', 'inInnerCatch', 'rethrow']);
});

it('lists the rethrows of every catch around a node', function (): void {
    // A rethrow by any catch on the path counts, an outer one around an inner that keeps included.
    $statements = (new ParserFactory)->createForHostVersion()->parse(<<<'PHP'
        <?php
        try { kept(); } catch (A $e) { report($e); }
        try { rethrown(); } catch (A $e) { if ($x) { throw $e; } throw $e; }
        try { try { inner(); } catch (A) {} } catch (B $e) { throw $e; }
        PHP) ?? [];

    $at = static function (string $name) use ($statements): int {
        foreach ((new NodeFinder)->findInstanceOf($statements, Node\Expr\FuncCall::class) as $call) {
            if ($call->name instanceof Node\Name && $call->name->toString() === $name) {
                return $call->getStartFilePos();
            }
        }

        return -1;
    };

    expect(EnclosingCatches::rethrowsAt($statements, $at('kept')))->toBe([])
        ->and(EnclosingCatches::rethrowsAt($statements, $at('rethrown')))->toHaveCount(2)
        ->and(EnclosingCatches::rethrowsAt($statements, $at('inner')))->toHaveCount(1)
        ->and(EnclosingCatches::rethrowsAt($statements, -1))->toBe([]);
});

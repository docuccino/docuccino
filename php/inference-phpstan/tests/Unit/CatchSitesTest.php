<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Throwing\CatchSites;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Which catches are in force around a call, read off a body's statements: only a `try` block is guarded, a
 * catch or `finally` body throws past its own catches, nesting stacks them innermost first, and a nested
 * function's body is its own and is not entered.
 */
it('pairs every call inside a try with the catches around it', function (): void {
    $source = <<<'PHP'
        <?php
        outside();
        try {
            guarded(function () { inClosure(); });
            try {
                nested();
            } catch (Inner $e) {
                inInnerCatch();
            }
        } catch (Outer $e) {
            inCatch();
        } finally {
            inFinally();
        }
        PHP;
    $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
    $sites = CatchSites::in($statements);

    $calls = [];
    foreach ((new NodeFinder)->findInstanceOf($statements, Node\Expr\FuncCall::class) as $call) {
        $calls[$call->name instanceof Node\Name ? $call->name->toString() : '?'] = $call;
    }
    $caught = static fn (string $name): array => array_map(
        static fn (Node\Stmt\Catch_ $catch): string => implode('|', array_map(static fn (Node\Name $type): string => $type->toString(), $catch->types)),
        $sites->around($calls[$name]),
    );

    expect($caught('guarded'))->toBe(['Outer'])
        ->and($caught('nested'))->toBe(['Inner', 'Outer'])
        ->and($caught('inInnerCatch'))->toBe(['Outer'])
        ->and($caught('inCatch'))->toBe([])
        ->and($caught('inFinally'))->toBe([])
        ->and($caught('outside'))->toBe([])
        // The closure is a body of its own: its calls answer to the closure's own `try`, read when it is.
        ->and($caught('inClosure'))->toBe([])
        ->and(array_map(
            static fn (Node\Expr\CallLike $call): string => $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name ? $call->name->toString() : '?',
            $sites->guarded(),
        ))->toBe(['guarded', 'nested', 'inInnerCatch']);
});

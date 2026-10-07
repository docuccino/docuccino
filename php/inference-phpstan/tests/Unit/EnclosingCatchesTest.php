<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Throwing\EnclosingCatches;
use PhpParser\Node;
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
    'after the try' => ['try { x(); } catch (A) {} here();', []],
    // A function's body runs wherever it is called from, so a try around where it is WRITTEN takes nothing.
    'inside a closure in the try' => ['try { $f = function () { here(); }; } catch (A) {}', []],
    'inside an anonymous class in the try' => ['try { $o = new class { function m() { here(); } }; } catch (A) {}', []],
]);

it('reads nothing where the offset is unknown', function (): void {
    $statements = (new ParserFactory)->createForHostVersion()->parse('<?php try { here(); } catch (A) {}') ?? [];

    expect(EnclosingCatches::around($statements, -1))->toBe([]);
});

it('lists the calls a try guards, under the same boundaries', function (): void {
    $statements = (new ParserFactory)->createForHostVersion()->parse(<<<'PHP'
        <?php
        outside();
        try {
            guarded(function () { inClosure(); });
            try { nested(); } catch (A) { inInnerCatch(); }
        } catch (B) {
            inCatch();
        } finally {
            inFinally();
        }
        PHP) ?? [];

    expect(array_map(
        static fn (Node\Expr\CallLike $call): string => $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name ? $call->name->toString() : '?',
        EnclosingCatches::guardedIn($statements),
    ))->toBe(['guarded', 'nested', 'inInnerCatch']);
});

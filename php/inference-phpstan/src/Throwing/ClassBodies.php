<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use PhpParser\Node;

/**
 * Where the HTTP-status read gets a class's method bodies from, and who folds an expression written in one.
 * The hierarchy, the visibility and the parameter defaults are all reflection, which needs nothing analysed;
 * only these two answers do, and behind them the read is ordinary code.
 *
 * @internal
 */
interface ClassBodies
{
    /**
     * Every method the class declares in `$file`, by method name → its statements. Empty where nothing
     * readable came back — an unparsable file, a class whose bodies were stripped.
     *
     * @return array<string, array<array-key, Node\Stmt>>
     */
    public function methods(string $file, string $class): array;

    /**
     * The constant integer an expression written in that class's `$method` folds to — a literal and a class
     * constant alike — or null where it is not one. The method is named because a status is read out of a
     * constructor and out of the static factory that calls it, and an expression folds in the scope of the
     * body it was written in.
     */
    public function foldInt(string $file, string $class, string $method, Node\Expr $expr): ?int;
}

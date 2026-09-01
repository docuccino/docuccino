<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use PhpParser\Node;

/**
 * Where {@see HttpExceptionStatus} gets a class's bodies from, and who folds an expression written in one.
 * The hierarchy, the visibility and the parameter defaults are all reflection, which needs nothing analysed;
 * only these two answers do, and behind them the status read is ordinary code.
 *
 * @internal
 */
interface ConstructorSource
{
    /**
     * Every method the class declares in `$file`, by method name → its statements. Empty where nothing
     * readable came back — an unparsable file, a class whose bodies were stripped.
     *
     * @return array<string, array<array-key, Node\Stmt>>
     */
    public function methods(string $file, string $class): array;

    /**
     * The constant integer an expression written in that class's constructor folds to — a literal and a
     * class constant alike — or null where it is not one.
     */
    public function foldInt(string $file, string $class, Node\Expr $expr): ?int;
}

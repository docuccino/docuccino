<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;

/**
 * The folded arguments of one call, for the readers that index a paginator terminal's signature. A
 * positional argument lands under its 0-based index and a named one under its parameter name, each a
 * scalar or null where it was written but would not fold — so `array_key_exists` is what separates
 * "absent" from "written and unresolvable".
 *
 * A spread has no position of its own: it fills its index and every later one from a sequence, so a
 * position that looks absent may well be supplied. Nothing about such a call is indexable, and the whole
 * answer is null rather than an array whose gaps read as defaults the call never took.
 */
final class FoldedArguments
{
    /** @return array<array-key, string|int|float|bool|null>|null */
    public static function of(Node\Expr\MethodCall|Node\Expr\StaticCall $call, TypeScope $scope): ?array
    {
        $args = [];

        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->unpack) {
                return null;
            }

            $value = $scope->constantValueOf($arg->value);
            $args[$arg->name?->toString() ?? $index] = $value !== null && $value->isScalar() ? $value->scalar : null;
        }

        return $args;
    }
}

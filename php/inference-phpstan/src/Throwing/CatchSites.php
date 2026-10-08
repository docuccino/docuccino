<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use PhpParser\Node;

/**
 * The calls one body makes inside a `try`, each with the catches in force around it. Read off the body's
 * own statements rather than off its throw points, because a call whose declared classes a catch took may
 * leave no point at all — and a closure it was handed still runs. A nested function or class is its own
 * body and is not entered.
 *
 * @internal
 */
final class CatchSites
{
    /**
     * @param  array<string, list<Node\Stmt\Catch_>>  $catches  by {@see key()}
     * @param  list<Node\Expr\CallLike>  $guarded
     */
    private function __construct(
        private readonly array $catches,
        private readonly array $guarded,
    ) {}

    /** @param  array<Node\Stmt>  $statements */
    public static function in(array $statements): self
    {
        $catches = [];
        $guarded = [];
        foreach ($statements as $statement) {
            self::collect($statement, [], $catches, $guarded);
        }

        return new self($catches, $guarded);
    }

    /**
     * The catches around one call, innermost first; none where it is not inside a `try`.
     *
     * @return list<Node\Stmt\Catch_>
     */
    public function around(Node $call): array
    {
        return $this->catches[self::key($call)] ?? [];
    }

    /**
     * Every call inside a `try`, in source order.
     *
     * @return list<Node\Expr\CallLike>
     */
    public function guarded(): array
    {
        return $this->guarded;
    }

    /** Start and end offset: a chained call starts where the call it is chained on does. */
    public static function key(Node $node): string
    {
        return $node->getStartFilePos().':'.$node->getEndFilePos();
    }

    /**
     * @param  list<Node\Stmt\Catch_>  $around
     * @param  array<string, list<Node\Stmt\Catch_>>  $catches
     * @param  list<Node\Expr\CallLike>  $guarded
     */
    private static function collect(Node $node, array $around, array &$catches, array &$guarded): void
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return;
        }

        if ($node instanceof Node\Stmt\TryCatch) {
            // Only the `try` block is guarded by the catches; a catch or `finally` body throws past them.
            foreach ($node->stmts as $statement) {
                self::collect($statement, [...array_values($node->catches), ...$around], $catches, $guarded);
            }
            foreach ($node->catches as $catch) {
                self::collect($catch, $around, $catches, $guarded);
            }
            if ($node->finally !== null) {
                self::collect($node->finally, $around, $catches, $guarded);
            }

            return;
        }

        if ($node instanceof Node\Expr\CallLike && $around !== []) {
            $catches[self::key($node)] = $around;
            $guarded[] = $node;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            foreach (is_array($child) ? $child : [$child] as $each) {
                if ($each instanceof Node) {
                    self::collect($each, $around, $catches, $guarded);
                }
            }
        }
    }
}

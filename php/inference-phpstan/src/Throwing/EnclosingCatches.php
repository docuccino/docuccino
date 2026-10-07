<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use PhpParser\Node;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\MethodReturnStatementsNode;

/**
 * The classes every `catch` around one offset of a body names, read off the source: what a catch takes is
 * PHP's rule — anything the try's own statements raise that is an instance of a named class — and the
 * analyser's point for an undeclared call states it differently on PHPStan 2.2 and 2.3. A catch or `finally`
 * body is outside its own try, and a nested function or class is a boundary.
 *
 * @internal
 */
final class EnclosingCatches
{
    /**
     * @return list<Node\Name> empty where the offset is unknown
     */
    public static function of(MethodReturnStatementsNode|ClosureReturnStatementsNode $body, Node $node): array
    {
        return self::around(self::statements($body), $node->getStartFilePos());
    }

    /**
     * Every call the body makes inside a `try` block, in source order. A call whose declared classes a catch
     * took may leave the analyser no point at all, and a closure it was handed still runs.
     *
     * @return list<Node\Expr\CallLike>
     */
    public static function guardedCalls(MethodReturnStatementsNode|ClosureReturnStatementsNode $body): array
    {
        return self::guardedIn(self::statements($body));
    }

    /**
     * @param  array<Node>  $nodes
     * @return list<Node\Expr\CallLike>
     */
    public static function guardedIn(array $nodes): array
    {
        $calls = [];
        foreach ($nodes as $node) {
            self::collect($node, false, $calls);
        }

        return $calls;
    }

    /**
     * @param  array<Node>  $nodes
     * @return list<Node\Name>
     */
    public static function around(array $nodes, int $offset): array
    {
        return $offset < 0 ? [] : (self::walk($nodes, $offset) ?? []);
    }

    /**
     * @param  array<Node>  $nodes
     * @return list<Node\Name>|null null where the offset sits behind a function or class boundary
     */
    private static function walk(array $nodes, int $offset): ?array
    {
        foreach ($nodes as $node) {
            if (self::covers([$node], $offset)) {
                return self::within($node, $offset);
            }
        }

        return [];
    }

    /** @return list<Node\Name>|null */
    private static function within(Node $node, int $offset): ?array
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return null;
        }

        if ($node instanceof Node\Stmt\TryCatch) {
            if (! self::covers($node->stmts, $offset)) {
                return self::walk($node->finally === null ? $node->catches : [...$node->catches, $node->finally], $offset);
            }

            $inner = self::walk($node->stmts, $offset);
            if ($inner === null) {
                return null;
            }

            $names = [];
            foreach ($node->catches as $catch) {
                foreach ($catch->types as $type) {
                    $names[] = $type;
                }
            }

            return [...$names, ...$inner];
        }

        $children = [];
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node) {
                $children[] = $child;
            } elseif (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node) {
                        $children[] = $item;
                    }
                }
            }
        }

        return self::walk($children, $offset);
    }

    /** @return array<Node\Stmt> */
    private static function statements(MethodReturnStatementsNode|ClosureReturnStatementsNode $body): array
    {
        return $body instanceof MethodReturnStatementsNode
            ? $body->getStatements()
            : $body->getClosureExpr()->stmts;
    }

    /**
     * The same boundaries {@see within()} keeps: only a `try` block is guarded, and a nested function or
     * class is not entered.
     *
     * @param  list<Node\Expr\CallLike>  $calls
     */
    private static function collect(Node $node, bool $guarded, array &$calls): void
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return;
        }

        if ($node instanceof Node\Stmt\TryCatch) {
            foreach ($node->stmts as $statement) {
                self::collect($statement, true, $calls);
            }
            foreach ($node->finally === null ? $node->catches : [...$node->catches, $node->finally] as $outside) {
                self::collect($outside, $guarded, $calls);
            }

            return;
        }

        if ($guarded && $node instanceof Node\Expr\CallLike) {
            $calls[] = $node;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            foreach (is_array($child) ? $child : [$child] as $item) {
                if ($item instanceof Node) {
                    self::collect($item, $guarded, $calls);
                }
            }
        }
    }

    /**
     * @param  array<Node>  $nodes
     */
    private static function covers(array $nodes, int $offset): bool
    {
        foreach ($nodes as $node) {
            if ($node->getStartFilePos() <= $offset && $offset <= $node->getEndFilePos()) {
                return true;
            }
        }

        return false;
    }
}

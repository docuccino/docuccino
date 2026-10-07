<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use PhpParser\Node;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\MethodReturnStatementsNode;

/**
 * The classes every `catch` around one offset of a body names, read off the source rather than off the
 * analyser's point, whose type for an undeclared call is not PHP's rule: a catch takes anything the try's own
 * statements raise that is an instance of a class it names. A catch that rethrows its own variable takes
 * nothing, a catch or `finally` body is outside its own try, and a nested function or class is a boundary.
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
     * The `throw`s by which a catch around the node rethrows what it caught. The analyser drops a point under
     * a catch wide enough to take all it can name, so where a rethrow cannot spell what it lets out, nothing
     * else says so.
     *
     * @return list<Node\Expr\Throw_>
     */
    public static function rethrowsAround(MethodReturnStatementsNode|ClosureReturnStatementsNode $body, Node $node): array
    {
        return self::rethrowsAt(self::statements($body), $node->getStartFilePos());
    }

    /**
     * @param  array<Node>  $nodes
     * @return list<Node\Expr\Throw_>
     */
    public static function rethrowsAt(array $nodes, int $offset): array
    {
        $rethrows = [];
        foreach (self::catchesAt($nodes, $offset) as $catch) {
            foreach (self::rethrows($catch) as $rethrow) {
                $rethrows[] = $rethrow;
            }
        }

        return $rethrows;
    }

    /**
     * Every call and `throw` the body makes inside a `try` block, in source order. Where a catch took all the
     * analyser said one raises it may leave no point at all, yet a closure a call was handed still runs, and
     * a rethrowing catch still lets out what it took.
     *
     * @return list<Node\Expr\CallLike|Node\Expr\Throw_>
     */
    public static function guarded(MethodReturnStatementsNode|ClosureReturnStatementsNode $body): array
    {
        return self::guardedIn(self::statements($body));
    }

    /**
     * @param  array<Node>  $nodes
     * @return list<Node\Expr\CallLike|Node\Expr\Throw_>
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
        $names = [];
        foreach (self::catchesAt($nodes, $offset) as $catch) {
            if (self::rethrows($catch) === []) {
                foreach ($catch->types as $type) {
                    $names[] = $type;
                }
            }
        }

        return $names;
    }

    /**
     * @param  array<Node>  $nodes
     * @return list<Node\Stmt\Catch_> outermost first
     */
    private static function catchesAt(array $nodes, int $offset): array
    {
        return $offset < 0 ? [] : (self::walk($nodes, $offset) ?? []);
    }

    /**
     * @param  array<Node>  $nodes
     * @return list<Node\Stmt\Catch_>|null outermost first; null where the offset sits behind a function or class boundary
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

    /** @return list<Node\Stmt\Catch_>|null */
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

            return [...array_values($node->catches), ...$inner];
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

    /**
     * Every `throw` of the very exception the catch caught, on any path: what leaves through one is whatever
     * the try raised, so the catch has taken none of it. A closure written in the body may hold the variable
     * too, and is read; a function or class cannot, and is not.
     *
     * @return list<Node\Expr\Throw_>
     */
    private static function rethrows(Node\Stmt\Catch_ $catch): array
    {
        if ($catch->var === null || ! is_string($catch->var->name)) {
            return [];
        }

        $found = [];
        foreach ($catch->stmts as $statement) {
            self::collectRethrows($statement, $catch->var->name, $found);
        }

        return $found;
    }

    /**
     * @param  list<Node\Expr\Throw_>  $found
     */
    private static function collectRethrows(Node $node, string $name, array &$found): void
    {
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassLike) {
            return;
        }

        if ($node instanceof Node\Expr\Throw_
            && $node->expr instanceof Node\Expr\Variable
            && $node->expr->name === $name
        ) {
            $found[] = $node;
        }

        foreach ($node->getSubNodeNames() as $sub) {
            $child = $node->{$sub};
            foreach (is_array($child) ? $child : [$child] as $item) {
                if ($item instanceof Node) {
                    self::collectRethrows($item, $name, $found);
                }
            }
        }
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
     * @param  list<Node\Expr\CallLike|Node\Expr\Throw_>  $calls
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

        if ($guarded && ($node instanceof Node\Expr\CallLike || $node instanceof Node\Expr\Throw_)) {
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

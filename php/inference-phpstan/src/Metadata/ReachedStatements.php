<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * The top-level statements of a constructor that every path completing it runs: those before the first
 * statement holding a `return` or a `goto`. A path that throws or never returns builds no object, so it
 * leaves nothing out. The one reading both the fixed-value and the initialisation readers take of
 * "assigned at top" and of "`parent::__construct()` runs".
 *
 * @internal
 */
final class ReachedStatements
{
    /**
     * @param  array<Node>  $statements
     * @return list<Node\Stmt>
     */
    public static function of(array $statements): array
    {
        $finder = new NodeFinder;
        $reached = [];
        foreach ($statements as $statement) {
            if (! $statement instanceof Node\Stmt
                || $finder->findFirst($statement, static fn (Node $node): bool => $node instanceof Node\Stmt\Return_ || $node instanceof Node\Stmt\Goto_) !== null
            ) {
                break;
            }

            $reached[] = $statement;
        }

        return $reached;
    }

    /** The value a `$this->name = …;` statement assigns, or null where it is not one. */
    public static function assignment(Node\Stmt $statement, string $name): ?Node\Expr
    {
        return $statement instanceof Node\Stmt\Expression
            && $statement->expr instanceof Node\Expr\Assign
            && $statement->expr->var instanceof Node\Expr\PropertyFetch
            && $statement->expr->var->var instanceof Node\Expr\Variable
            && $statement->expr->var->var->name === 'this'
            && $statement->expr->var->name instanceof Node\Identifier
            && $statement->expr->var->name->toString() === $name
            ? $statement->expr->expr
            : null;
    }

    /** The call, where the statement is `parent::__construct(…);`. */
    public static function parentConstruct(Node\Stmt $statement): ?Node\Expr\StaticCall
    {
        return $statement instanceof Node\Stmt\Expression
            && $statement->expr instanceof Node\Expr\StaticCall
            && $statement->expr->class instanceof Node\Name
            && $statement->expr->class->toLowerString() === 'parent'
            && $statement->expr->name instanceof Node\Identifier
            && $statement->expr->name->toLowerString() === '__construct'
            && ! $statement->expr->isFirstClassCallable()
            ? $statement->expr
            : null;
    }
}

<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Inference\PhpStan\Throwing\ClassBodies;
use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * A container-free {@see ClassBodies}: bodies off a plain parse, statuses off php-parser's own
 * constant evaluator with `constant()` behind it, so a class constant folds the way the analyser folds it.
 * Names are resolved as PHPStan resolves them, which is what the class-name comparisons downstream expect.
 *
 * Where it differs from the analyser is the one place nothing asks it to agree: a trait's methods are keyed
 * under the TRAIT here and under the using class there, and a class that uses a trait declines before
 * either is read.
 */
final class ParsedBodies implements ClassBodies
{
    /** @var array<string, array<string, array<string, array<array-key, Node\Stmt>>>> file → class → method → body */
    private array $cache = [];

    public function methods(string $file, string $class): array
    {
        return $this->parse($file)[$class] ?? [];
    }

    public function foldInt(string $file, string $class, string $method, Node\Expr $expr): ?int
    {
        $evaluator = new ConstExprEvaluator(static function (Node\Expr $expr): mixed {
            $name = match (true) {
                $expr instanceof Node\Expr\ClassConstFetch,
                $expr instanceof Node\Expr\ConstFetch => self::constantName($expr),
                default => null,
            };

            return $name !== null && defined($name) ? constant($name) : throw new ConstExprEvaluationException;
        });

        try {
            $value = $evaluator->evaluateSilently($expr);
        } catch (ConstExprEvaluationException) {
            return null;
        }

        return is_int($value) ? $value : null;
    }

    private static function constantName(Node\Expr\ClassConstFetch|Node\Expr\ConstFetch $expr): ?string
    {
        if ($expr instanceof Node\Expr\ConstFetch) {
            return $expr->name->toString();
        }

        return $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier
            ? $expr->class->toString().'::'.$expr->name->toString()
            : null;
    }

    /**
     * @return array<string, array<string, array<array-key, Node\Stmt>>>
     */
    private function parse(string $file): array
    {
        if (isset($this->cache[$file])) {
            return $this->cache[$file];
        }

        $source = is_file($file) ? (string) file_get_contents($file) : '';
        $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $resolved = (new NodeTraverser(new NameResolver))->traverse($statements);

        $classes = [];
        foreach ((new NodeFinder)->findInstanceOf($resolved, Node\Stmt\ClassLike::class) as $class) {
            $name = (string) $class->namespacedName;
            foreach ($class->getMethods() as $method) {
                $classes[$name][$method->name->toString()] = $method->stmts ?? [];
            }
        }

        return $this->cache[$file] = $classes;
    }
}

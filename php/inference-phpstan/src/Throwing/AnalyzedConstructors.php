<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use PhpParser\Node;
use PHPStan\Type\Constant\ConstantIntegerType;

/**
 * The analyser's answer to {@see ConstructorSource}: bodies off the file harvest, and folding through the
 * constructor's own scope, so a class constant (`Response::HTTP_CONFLICT`) reads the same as the literal
 * beside it. The scope is the constructor's end scope — a constant's value does not depend on where in the
 * body it is asked for.
 *
 * @internal
 */
final class AnalyzedConstructors implements ConstructorSource
{
    public function __construct(private readonly FileAnalyzer $fileAnalyzer) {}

    public function methods(string $file, string $class): array
    {
        $prefix = $class.'::';

        $methods = [];
        foreach ($this->fileAnalyzer->analyze($file) as $key => $method) {
            if (str_starts_with($key, $prefix)) {
                $methods[$method->getMethodName()] = $method->getStatements();
            }
        }

        return $methods;
    }

    public function foldInt(string $file, string $class, Node\Expr $expr): ?int
    {
        $body = $this->fileAnalyzer->method($file, $class, '__construct');
        if ($body === null) {
            return null;
        }

        $type = $this->fileAnalyzer->stableScope($body->getStatementResult()->getScope())->getType($expr);

        return $type instanceof ConstantIntegerType ? $type->getValue() : null;
    }
}

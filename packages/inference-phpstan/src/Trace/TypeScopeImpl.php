<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PHPStan\Analyser\Scope;

/**
 * The engine-side {@see TypeScope}: the only type-engine surface a visitor sees.
 * Wraps a PHPStan `Scope` + {@see TypeTranslator}; `PhpParser\Node` crosses the
 * boundary while `PHPStan\*` stops here (design §4, Spike B).
 */
final class TypeScopeImpl implements TypeScope
{
    public function __construct(
        private readonly Scope $scope,
        private readonly TypeTranslator $translator,
    ) {}

    public function typeOf(Node\Expr $expr): DType
    {
        return $this->translator->translate($this->scope->getType($expr));
    }

    /**
     * The Scramble-Pro-beater. Three cases, in this load-bearing precedence
     * (Spike B):
     *
     *   1. array literal      → recurse per item at the AST level, so factory
     *      calls inside survive as descriptors (PHPStan would flatten them);
     *   2. factory static-call → a call descriptor {factory, args}, folded
     *      BEFORE asking PHPStan for the type (which would collapse it to the
     *      factory's return class);
     *   3. genuine literal     → defer to PHPStan constant folding.
     *
     * Returns null when nothing constant is recoverable.
     */
    public function constantValueOf(Node\Expr $expr): ?ConstValue
    {
        // 1. Array literal — items are always ArrayItem in php-parser v5.
        if ($expr instanceof Node\Expr\Array_) {
            $items = [];
            foreach ($expr->items as $item) {
                $items[] = $this->constantValueOf($item->value)
                    ?? ConstValue::unknown('non-constant array item');
            }

            return ConstValue::array($items);
        }

        // 2. Factory static-call — capture the call, do not fold it to its type.
        if ($expr instanceof Node\Expr\StaticCall
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
        ) {
            $factory = $this->shortName($expr->class->toString()).'::'.$expr->name->toString();
            $args = [];
            foreach ($expr->getArgs() as $arg) {
                $args[] = $this->constantValueOf($arg->value)
                    ?? ConstValue::unknown('non-constant factory arg');
            }

            return ConstValue::descriptor($factory, $args);
        }

        // 3. Genuine literal reached through any expression — let PHPStan fold it.
        $type = $this->scope->getType($expr);

        $strings = $type->getConstantStrings();
        if (count($strings) === 1) {
            return ConstValue::scalar($strings[0]->getValue());
        }

        if ($type->isConstantScalarValue()->yes()) {
            $values = $type->getConstantScalarValues();
            if (count($values) === 1) {
                return ConstValue::scalar($values[0]);
            }
        }

        return null;
    }

    public function location(Node $node): SourceLocation
    {
        $pos = $node->getStartFilePos();

        return new SourceLocation(
            $this->scope->getFile(),
            $node->getStartLine(),
            $pos < 0 ? null : $pos,
        );
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts) ?: $fqcn;
    }
}

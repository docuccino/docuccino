<?php

declare(strict_types=1);

namespace Docuccino\SpikeB;

use PhpParser\Node;
use PHPStan\Analyser\Scope;

/**
 * Prototype of the plan's `TypeScope` — the ONLY PHPStan-touching surface the
 * TraceVisitor sees. `PhpParser\Node` crosses the boundary; `PHPStan\*` does not
 * leak past these methods.
 *
 *   public function typeOf(Node\Expr $e): DType;             // here: describe() string
 *   public function constantValueOf(Node\Expr $e): ?ConstValue;
 *   public function location(Node $n): SourceLocation;
 *
 * In the real engine `typeOf` returns a translated `DType`; for the spike we
 * only need the receiver's class name for descent, so we expose `objectClassOf`
 * plus a describe() string. That is enough to prove the contract shape.
 */
final class TypeScope
{
    public function __construct(private readonly Scope $scope) {}

    /** Human-readable type of an expression (stand-in for DType translation). */
    public function typeOf(Node\Expr $e): string
    {
        return $this->scope->getType($e)->describe(\PHPStan\Type\VerbosityLevel::precise());
    }

    /**
     * The single FQCN a receiver expression resolves to, or null when it is not
     * a single object type (union, scalar, mixed…). Used for descent + terminal
     * matching against the receiver's class.
     */
    public function objectClassOf(Node\Expr $e): ?string
    {
        $names = $this->scope->getType($e)->getObjectClassNames();

        return count($names) === 1 ? $names[0] : null;
    }

    /**
     * The Scramble-Pro-beater. Recover a constant value from an expression:
     *   - array literal  → ConstValue::array (recurse per item),
     *   - factory static-call (AllowedFilter::exact('status')) → descriptor
     *     capturing factory name + folded args (NOT collapsed to its type),
     *   - otherwise defer to PHPStan's constant folding (ConstantStringType /
     *     constant scalar) for genuine literals reached through any indirection.
     *
     * Returns null when nothing constant could be recovered.
     */
    public function constantValueOf(Node\Expr $e): ?ConstValue
    {
        // 1. Array literal — walk items at the AST level so factory calls inside
        //    survive as descriptors (PHPStan would flatten them to object types).
        if ($e instanceof Node\Expr\Array_) {
            $items = [];
            foreach ($e->items as $item) {
                if (! $item instanceof Node\ArrayItem) {
                    return ConstValue::unknown('spread/hole in array literal');
                }
                $items[] = $this->constantValueOf($item->value)
                    ?? ConstValue::unknown('non-constant array item');
            }

            return ConstValue::array($items);
        }

        // 2. Factory static-call — capture the call, don't fold it to its type.
        if ($e instanceof Node\Expr\StaticCall
            && $e->class instanceof Node\Name
            && $e->name instanceof Node\Identifier
        ) {
            $factory = $this->shortName($e->class->toString()).'::'.$e->name->toString();
            $args = [];
            foreach ($e->getArgs() as $arg) {
                $args[] = $this->constantValueOf($arg->value)
                    ?? ConstValue::unknown('non-constant factory arg');
            }

            return ConstValue::descriptor($factory, $args);
        }

        // 3. Genuine literal reached through any expression — let PHPStan fold it.
        $type = $this->scope->getType($e);

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

    /** @return array{file: string, line: int, pos: int} */
    public function location(Node $n): array
    {
        $pos = $n->getStartFilePos();

        return [
            'file' => $this->scope->getFile(),
            'line' => $n->getStartLine(),
            'pos' => $pos < 0 ? PHP_INT_MAX : $pos,
        ];
    }

    public function currentClass(): ?string
    {
        return $this->scope->getClassReflection()?->getName();
    }

    public function currentFunction(): ?string
    {
        return $this->scope->getFunction()?->getName();
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}

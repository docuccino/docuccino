<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use PhpParser\Node;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Node\Expr\PropertyInitializationExpr;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Type\NeverType;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Whether the constructor a class runs assigns a typed property on every path that completes — what decides
 * whether `json_encode` writes its key. Read off the analyser's tracking of property initialisation at each
 * end of the constructor, so a branch, an early `return` or a never-returning call is judged as PHP runs it,
 * and a `$this->method()` the class declares is followed as the analyser follows it. A path that skips the
 * property is a proof only where nothing the analyser does not follow takes part in building the object
 * ({@see ConstructionEscape}); anything else is left unanswered. The analyser tracks a property only in the
 * constructor of the class declaring it, so a constructor that runs `parent::__construct()` on every
 * completing path ({@see ReachedStatements}) takes the parent constructor's answer, read the same way and
 * recursively, unless it assigns the property itself after the call.
 *
 * @internal
 */
final class ConstructorInitialisation
{
    public function __construct(
        private readonly FileAnalyzer $files,
        private readonly ConstructionEscape $escape = new ConstructionEscape,
    ) {}

    /**
     * True where every completing path assigns it, false where one completes without it, null where the
     * property needs no answer (untyped, defaulted, promoted) or the analysis cannot prove either.
     *
     * @param  ReflectionClass<object>  $class
     */
    public function of(ReflectionClass $class, ReflectionProperty $property): ?bool
    {
        $constructor = $class->getConstructor();
        if (! $property->hasType() || $property->hasDefaultValue() || $property->isPromoted() || $constructor === null) {
            return null;
        }

        try {
            return $this->ran($class, $constructor->getDeclaringClass(), $property);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The answer for the constructor `$declaring` writes, run to build a `$class`.
     *
     * @param  ReflectionClass<object>  $class
     * @param  ReflectionClass<object>  $declaring
     */
    private function ran(ReflectionClass $class, ReflectionClass $declaring, ReflectionProperty $property): ?bool
    {
        $owner = $property->getDeclaringClass()->getName();
        if ($owner !== $declaring->getName() && ! $declaring->isSubclassOf($owner)) {
            return null;
        }

        $file = $declaring->getConstructor()?->getFileName();
        $body = $file === null || $file === false ? null : $this->files->method($file, $declaring->getName(), '__construct');
        if ($body === null || $body->getClassReflection()->getName() !== $declaring->getName()) {
            return null;
        }

        // A helper the class being built overrides is not the body the analyser read.
        $statements = $body->getStatements();
        if (! $this->escape->dispatches($class, $declaring, $statements)) {
            return null;
        }

        return $owner === $declaring->getName()
            ? $this->tracked($class, $declaring, $property->getName(), $body)
            : $this->composed($class, $declaring, $property, $statements);
    }

    /**
     * The analyser's answer, for a constructor of the class declaring the property.
     *
     * @param  ReflectionClass<object>  $class
     * @param  ReflectionClass<object>  $declaring
     */
    private function tracked(ReflectionClass $class, ReflectionClass $declaring, string $property, MethodReturnStatementsNode $body): ?bool
    {
        $ends = [];
        foreach ($body->getExecutionEnds() as $end) {
            $result = $end->getStatementResult();
            $node = $end->getNode();
            // A statement that throws or calls a never-returning function ends no construction.
            if ($result->isAlwaysTerminating() && $node instanceof Expression) {
                $type = $this->files->stableScope($result->getScope())->getType($node->expr);
                if ($type instanceof NeverType && $type->isExplicit()) {
                    continue;
                }
            }
            $ends[] = $result->getScope();
        }
        foreach ($body->getReturnStatements() as $return) {
            $ends[] = $return->getScope();
        }

        // No completing path seen — a constructor that always throws, or a body the analyser was not given.
        if ($ends === []) {
            return null;
        }

        // The analyser's marker for "assigned by now"; no API names it. AnalyserDriftTest guards the name.
        // @phpstan-ignore phpstanApi.constructor
        $assigned = new PropertyInitializationExpr($property);
        foreach ($ends as $scope) {
            if (! $this->files->stableScope($scope)->hasExpressionType($assigned)->yes()) {
                return $this->escape->possible($class, $declaring, $property, $body->getStatements()) ? null : false;
            }
        }

        return true;
    }

    /**
     * The answer for a constructor whose class inherits the property: the parent constructor's, where every
     * completing path runs it, and true where one of those statements assigns the property after it.
     *
     * @param  ReflectionClass<object>  $class
     * @param  ReflectionClass<object>  $declaring
     * @param  array<Node>  $statements
     */
    private function composed(ReflectionClass $class, ReflectionClass $declaring, ReflectionProperty $property, array $statements): ?bool
    {
        $call = null;
        foreach (ReachedStatements::of($statements) as $statement) {
            if ($call === null) {
                $call = ReachedStatements::parentConstruct($statement);
            } elseif (ReachedStatements::assignment($statement, $property->getName()) !== null) {
                return true;
            }
        }

        $parent = $declaring->getParentClass();
        $constructor = $parent === false ? null : $parent->getConstructor();
        if ($call === null || $constructor === null) {
            return null;
        }

        $inherited = $this->ran($class, $constructor->getDeclaringClass(), $property);
        if ($inherited !== false) {
            return $inherited;
        }

        // The parent's paths may skip it; that holds unless something here may assign it after all.
        return $this->escape->possible($class, $declaring, $property->getName(), $statements, $call) ? null : false;
    }
}

<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\Frame;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Type;

/**
 * The 3-layer exception-flow engine (design §6, corrected per Spike C):
 *
 *   1. PHPStan throw points. Noise rule (corrected): drop `!isExplicit()` points
 *      — those are ALWAYS bare `Throwable`; `canContainAnyThrowable` is NOT a
 *      discriminator (nearly every point, signal included, flags it).
 *   2. {@see KnownThrowers} registry — dual role: enrich explicit stubbed points
 *      with a status; rescue still-implicit forwarders (static `findOrFail`) by
 *      callee name, at `likely` confidence.
 *   3. Bounded descent (depth 3) into project-code callees with no `@throws`,
 *      memoised + cycle-guarded; the vendor-file gate — not depth — does the real
 *      containment.
 *
 * Result identity is `(fqcn, httpStatusHint)`: two aborts (403/404) are two
 * responses. Vendor-declared 500-class exceptions are demoted to `internal`;
 * dropped bare-`Throwable` noise is counted and never surfaced.
 */
final class ThrowAnalyzer
{
    private const CONFIDENCE_RANK = [
        ThrowConfidence::Certain->value => 3,
        ThrowConfidence::Declared->value => 2,
        ThrowConfidence::Likely->value => 1,
    ];

    /** @var array<string, int|null> */
    private array $statusByType;

    private int $droppedCount = 0;

    /** @var array<string, true> */
    private array $visitedFiles = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ProjectFilter $projectFilter,
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly KnownThrowers $knownThrowers,
        private readonly int $maxDepth = 3,
    ) {
        $this->statusByType = [
            KnownThrowers::HTTP_EXCEPTION => null, // resolved by folding the abort arg
            KnownThrowers::AUTHORIZATION_EXCEPTION => 403,
            KnownThrowers::MODEL_NOT_FOUND_EXCEPTION => 404,
            KnownThrowers::VALIDATION_EXCEPTION => 422,
        ];
    }

    /**
     * @return list<ThrownException>
     */
    public function analyze(MethodReturnStatementsNode $node, string $selfLabel): array
    {
        $this->droppedCount = 0;
        $this->visitedFiles = [];

        $raw = $this->analyzeMethod($node, $selfLabel, 0, [], []);

        return $this->dedupe($raw);
    }

    public function droppedCount(): int
    {
        return $this->droppedCount;
    }

    /**
     * @return list<string>
     */
    public function visitedFiles(): array
    {
        return array_keys($this->visitedFiles);
    }

    /**
     * @param  list<string>  $visited
     * @param  list<Frame>  $priorChain
     * @return list<ThrownException>
     */
    private function analyzeMethod(
        MethodReturnStatementsNode $methodNode,
        string $selfLabel,
        int $depth,
        array $visited,
        array $priorChain,
    ): array {
        $results = [];

        foreach ($methodNode->getStatementResult()->getThrowPoints() as $throwPoint) {
            $node = $throwPoint->getNode();
            $type = $throwPoint->getType();
            $scope = $throwPoint->getScope();
            $explicit = $throwPoint->isExplicit();
            $callee = $this->resolveCallee($node, $scope);
            $frame = $this->frame($selfLabel, $scope, $node);

            // --- Layer 2: KnownThrowers registry (keyed on resolved callee). ---
            $registryResult = $this->applyRegistry($callee, $node, $scope, $type, $explicit, $priorChain, $frame);
            if ($registryResult !== null) {
                $results[] = $registryResult;

                continue;
            }

            // --- Layer 1: explicit concrete type (literal throw, @throws, stub). ---
            if ($explicit && ! $this->isBareThrowable($type)) {
                foreach ($this->applyExplicit($callee, $node, $scope, $type, $priorChain, $frame) as $result) {
                    $results[] = $result;
                }

                continue;
            }

            // --- Layer 3: implicit bare Throwable — descend or drop. ---
            if (! $explicit) {
                $descended = $this->applyDescent($callee, $scope, $depth, $visited, $priorChain, $frame);
                if ($descended !== null) {
                    foreach ($descended as $result) {
                        $results[] = $result;
                    }

                    continue;
                }
                $this->droppedCount++;
            }
        }

        return $results;
    }

    /**
     * @param  array{name: string, recvClasses: list<string>}|null  $callee
     * @param  list<Frame>  $priorChain
     */
    private function applyRegistry(
        ?array $callee,
        Node $node,
        Scope $scope,
        Type $type,
        bool $explicit,
        array $priorChain,
        Frame $frame,
    ): ?ThrownException {
        if ($callee === null) {
            return null;
        }

        $thrower = $this->knownThrowers->forFunction($callee['name']);
        $status = null;
        if ($thrower !== null) {
            $status = $this->foldStatusArg($node, $scope, (int) $thrower->statusArgIndex);
        } else {
            $thrower = $this->knownThrowers->forMethod($callee['name']);
            if ($thrower !== null) {
                $status = $thrower->fixedStatus;
            }
        }

        if ($thrower === null) {
            return null;
        }

        // certain when PHPStan corroborated the same concrete type explicitly;
        // likely when we rescued a bare-Throwable implicit forwarder.
        $corroborated = $explicit && in_array($thrower->exceptionFqcn, $type->getObjectClassNames(), true);

        return new ThrownException(
            $thrower->exceptionFqcn,
            $status,
            [...$priorChain, $frame],
            $corroborated ? ThrowConfidence::Certain : ThrowConfidence::Likely,
            ThrowDisposition::Signal,
        );
    }

    /**
     * @param  array{name: string, recvClasses: list<string>}|null  $callee
     * @param  list<Frame>  $priorChain
     * @return list<ThrownException>
     */
    private function applyExplicit(
        ?array $callee,
        Node $node,
        Scope $scope,
        Type $type,
        array $priorChain,
        Frame $frame,
    ): array {
        // php-parser v5 models `throw` only as an expression (Node\Expr\Throw_).
        $isLiteral = $node instanceof Node\Expr\Throw_;

        // A declared (non-literal) exception documents intent only when it comes
        // from PROJECT code; a vendor call's @throws is internal plumbing.
        $calleeIsProject = ! $isLiteral && $callee !== null
            && $this->declaringProjectMethod($callee, $scope) !== null;

        $results = [];
        foreach ($this->concreteClasses($type) as $class) {
            $status = $this->statusForType($class);
            $kept = $isLiteral || $calleeIsProject || $status !== 500;
            $results[] = new ThrownException(
                $class,
                $status,
                [...$priorChain, $frame],
                $isLiteral ? ThrowConfidence::Certain : ThrowConfidence::Declared,
                $kept ? ThrowDisposition::Signal : ThrowDisposition::Internal,
            );
        }

        return $results;
    }

    /**
     * @param  array{name: string, recvClasses: list<string>}|null  $callee
     * @param  list<string>  $visited
     * @param  list<Frame>  $priorChain
     * @return list<ThrownException>|null null when there is nothing to descend into
     */
    private function applyDescent(
        ?array $callee,
        Scope $scope,
        int $depth,
        array $visited,
        array $priorChain,
        Frame $frame,
    ): ?array {
        $project = $callee !== null ? $this->declaringProjectMethod($callee, $scope) : null;
        if ($project === null || $depth >= $this->maxDepth) {
            return null;
        }

        $key = $project['class'].'::'.$project['method'];
        if (in_array($key, $visited, true)) {
            return []; // cycle guard — treated as descended (no drop)
        }

        $this->visitedFiles[$project['file']] = true;
        $childMap = $this->fileAnalyzer->analyze($project['file']);
        if (! isset($childMap[$project['method']])) {
            return [];
        }

        $childLabel = $this->shortFqcn($project['class']).'::'.$project['method'];

        return $this->analyzeMethod(
            $childMap[$project['method']],
            $childLabel,
            $depth + 1,
            [...$visited, $key],
            [...$priorChain, $frame],
        );
    }

    /**
     * @param  list<ThrownException>  $raw
     * @return list<ThrownException>
     */
    private function dedupe(array $raw): array
    {
        $byIdentity = [];
        foreach ($raw as $throw) {
            $key = $throw->identityKey();
            if (! isset($byIdentity[$key])
                || self::CONFIDENCE_RANK[$throw->confidence->value] > self::CONFIDENCE_RANK[$byIdentity[$key]->confidence->value]
            ) {
                $byIdentity[$key] = $throw;
            }
        }

        $list = array_values($byIdentity);
        usort($list, static function (ThrownException $a, ThrownException $b): int {
            return [$a->httpStatusHint ?? PHP_INT_MAX, $a->exceptionFqcn]
                <=> [$b->httpStatusHint ?? PHP_INT_MAX, $b->exceptionFqcn];
        });

        return $list;
    }

    private function frame(string $selfLabel, Scope $scope, Node $node): Frame
    {
        return new Frame($selfLabel, new SourceLocation($scope->getFile(), $node->getStartLine()));
    }

    /**
     * @return array{name: string, recvClasses: list<string>}|null
     */
    private function resolveCallee(Node $node, Scope $scope): ?array
    {
        if ($node instanceof Node\Expr\FuncCall) {
            return $node->name instanceof Node\Name
                ? ['name' => $node->name->toString(), 'recvClasses' => []]
                : null;
        }
        if ($node instanceof Node\Expr\MethodCall) {
            if (! $node->name instanceof Node\Identifier) {
                return null;
            }

            return [
                'name' => $node->name->toString(),
                'recvClasses' => $scope->getType($node->var)->getObjectClassNames(),
            ];
        }
        if ($node instanceof Node\Expr\StaticCall) {
            if (! $node->name instanceof Node\Identifier) {
                return null;
            }
            $recvClasses = $node->class instanceof Node\Name ? [$scope->resolveName($node->class)] : [];

            return ['name' => $node->name->toString(), 'recvClasses' => $recvClasses];
        }

        return null;
    }

    private function foldStatusArg(Node $node, Scope $scope, int $argIndex): ?int
    {
        if (! method_exists($node, 'getArgs')) {
            return null;
        }
        /** @var list<Node\Arg> $args */
        $args = $node->getArgs();
        if (! isset($args[$argIndex])) {
            return null;
        }
        $argType = $scope->getType($args[$argIndex]->value);

        return $argType instanceof ConstantIntegerType ? $argType->getValue() : null;
    }

    /**
     * @param  array{name: string, recvClasses: list<string>}  $callee
     * @return array{file: string, class: string, method: string}|null
     */
    private function declaringProjectMethod(array $callee, Scope $scope): ?array
    {
        foreach ($callee['recvClasses'] as $recvClass) {
            if (! $this->reflectionProvider->hasClass($recvClass)) {
                continue;
            }
            $classReflection = $this->reflectionProvider->getClass($recvClass);
            if (! $classReflection->hasMethod($callee['name'])) {
                continue;
            }
            $declaringClass = $classReflection->getMethod($callee['name'], $scope)->getDeclaringClass();
            $file = $declaringClass->getFileName();
            if ($file !== null && $this->projectFilter->isProjectFile($file)) {
                return ['file' => $file, 'class' => $declaringClass->getName(), 'method' => $callee['name']];
            }
        }

        return null;
    }

    /**
     * Concrete (non-`Throwable`/`Exception`) object class names on a type.
     *
     * @return list<string>
     */
    private function concreteClasses(Type $type): array
    {
        return array_values(array_filter(
            $type->getObjectClassNames(),
            static fn (string $name): bool => $name !== 'Throwable' && $name !== 'Exception',
        ));
    }

    private function isBareThrowable(Type $type): bool
    {
        return $this->concreteClasses($type) === [];
    }

    private function statusForType(string $fqcn): int
    {
        if (array_key_exists($fqcn, $this->statusByType) && $this->statusByType[$fqcn] !== null) {
            return $this->statusByType[$fqcn];
        }

        if ($this->reflectionProvider->hasClass($fqcn)) {
            $reflection = $this->reflectionProvider->getClass($fqcn);
            foreach ($this->statusByType as $known => $status) {
                if ($status !== null && $reflection->is($known)) {
                    return $status;
                }
            }
        }

        return 500; // internal / unhandled
    }

    private function shortFqcn(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }
}

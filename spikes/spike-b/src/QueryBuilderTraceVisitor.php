<?php

declare(strict_types=1);

namespace Docuccino\SpikeB;

use PhpParser\Node;

/**
 * The Query-Builder integration's TraceVisitor. This is the concrete thing the
 * plan's Phase-4 "Query Builder (trace-based, incl. configurable pagination
 * terminals)" integration becomes. Everything it needs it gets from `TypeScope`
 * — no PHPStan import here at all.
 *
 * Responsibilities inside enterNode:
 *   - harvest allowedFilters / allowedSorts / defaultSort literals off a
 *     QueryBuilder receiver (folding factory calls to descriptors),
 *   - flag paginating terminals reached on a builder receiver + fold per-page,
 *   - answer "descend?" = true for app-code method calls (so literals two calls
 *     deep are reached), false for vendor/magic terminals.
 */
final class QueryBuilderTraceVisitor implements TraceVisitor
{
    private const QUERY_BUILDER = 'Spatie\\QueryBuilder\\QueryBuilder';

    /**
     * @param list<string> $terminals        method names that terminate in pagination
     * @param list<string> $filterMethods    QB config method → allowedFilters bucket
     * @param list<string> $sortMethods      QB config method → allowedSorts bucket
     * @param list<string> $defaultSortMethods
     */
    public function __construct(
        public readonly TraceResult $result,
        private readonly array $terminals = ['paginate', 'simplePaginate', 'cursorPaginate', 'paginateList'],
        private readonly array $filterMethods = ['allowedFilters'],
        private readonly array $sortMethods = ['allowedSorts'],
        private readonly array $defaultSortMethods = ['defaultSort', 'defaultSorts'],
    ) {}

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        if (! $node instanceof Node\Expr\MethodCall && ! $node instanceof Node\Expr\StaticCall) {
            return false;
        }

        $name = $this->methodName($node);
        if ($name === null) {
            return false;
        }

        // --- Harvest QB config methods (instance calls on a QueryBuilder). ---
        if ($node instanceof Node\Expr\MethodCall && $this->receiverIsBuilder($node, $scope)) {
            if (in_array($name, $this->filterMethods, true)) {
                $this->harvestList($node, $scope, $this->result->allowedFilters);
            } elseif (in_array($name, $this->sortMethods, true)) {
                $this->harvestList($node, $scope, $this->result->allowedSorts);
            } elseif (in_array($name, $this->defaultSortMethods, true)) {
                $this->harvestList($node, $scope, $this->result->defaultSort);
            }
        }

        // --- Flag paginating terminals on a builder receiver. ---
        if (in_array($name, $this->terminals, true)
            && $node instanceof Node\Expr\MethodCall
            && $this->receiverIsBuilder($node, $scope)
        ) {
            $args = $node->getArgs();
            $perPage = null;
            if (isset($args[0])) {
                $cv = $scope->constantValueOf($args[0]->value);
                if ($cv !== null && $cv->isScalar() && is_int($cv->scalar)) {
                    $perPage = $cv->scalar;
                }
            }
            $this->result->terminalHits[] = [
                'terminal' => $name,
                'perPage' => $perPage,
                'receiver' => $scope->objectClassOf($node->var) ?? '?',
                'loc' => $scope->location($node),
            ];
        }

        // --- Descent decision: descend into app-code calls only. ---
        $callee = CalleeResolver::resolve($node, $scope);

        return $callee !== null && ! $callee->isVendor;
    }

    /**
     * Fold the args of a config call into constants. Each argument is either an
     * array literal (unwrapped one level — the Eos form) or a direct value
     * (variadic form); both are supported.
     *
     * @param list<ConstValue> $bucket
     */
    private function harvestList(Node\Expr\MethodCall $node, TypeScope $scope, array &$bucket): void
    {
        foreach ($node->getArgs() as $arg) {
            $value = $arg->value;
            if ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $item) {
                    if (! $item instanceof Node\ArrayItem) {
                        continue;
                    }
                    $bucket[] = $scope->constantValueOf($item->value)
                        ?? ConstValue::unknown('non-constant list item');
                }

                continue;
            }

            $bucket[] = $scope->constantValueOf($value)
                ?? ConstValue::unknown('non-constant list arg');
        }
    }

    private function receiverIsBuilder(Node\Expr\MethodCall $node, TypeScope $scope): bool
    {
        $class = $scope->objectClassOf($node->var);

        return $class !== null && is_a($class, self::QUERY_BUILDER, true);
    }

    private function methodName(Node\Expr\MethodCall|Node\Expr\StaticCall $node): ?string
    {
        return $node->name instanceof Node\Identifier ? $node->name->toString() : null;
    }
}

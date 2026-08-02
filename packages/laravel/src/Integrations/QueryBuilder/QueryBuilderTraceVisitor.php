<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;

/**
 * The Query-Builder integration's {@see TraceVisitor} — the productionised Spike B "Scramble-Pro-
 * beater". Pure semantics + harvesting through {@see TypeScope}; imports zero PHPStan. It recovers,
 * off any `Spatie\QueryBuilder\QueryBuilder` receiver at any chain depth (the engine descends into
 * app-code helpers, so the `ListQueryBuilder::for()` two-deep pattern works):
 *
 *   - `allowedFilters` / `allowedSorts` / `allowedIncludes` / `allowedFields` literals — strings and
 *     factory descriptors (`AllowedFilter::exact('status')`) folded at the AST level before PHPStan
 *     collapses them to a plain object type (the crux of Spike B);
 *   - `defaultSort`/`defaultSorts` documented defaults;
 *   - paginating terminals (`paginate`/`simplePaginate`/`cursorPaginate` plus any configured custom
 *     terminal, e.g. Eos's `paginateList`) with the per-page folded from the OUTERMOST call site.
 *
 * Every un-foldable allow-list entry is recorded on {@see QueryBuilderFacts::$unresolved} with its
 * source location, so a dynamic chain degrades to a named diagnostic rather than silence.
 */
final class QueryBuilderTraceVisitor implements TraceVisitor
{
    private const QUERY_BUILDER = 'Spatie\\QueryBuilder\\QueryBuilder';

    /**
     * Config method → the allow-list it fills and the default kind for a bare-string entry.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CONFIG_METHODS = [
        'allowedFilters' => ['filters', 'default'],
        'allowedSorts' => ['sorts', 'default'],
        'allowedIncludes' => ['includes', 'default'],
        'allowedFields' => ['fields', 'field'],
        'defaultSort' => ['defaultSorts', 'default'],
        'defaultSorts' => ['defaultSorts', 'default'],
    ];

    /**
     * Terminal method → paginator kind. Custom terminals (config) default to length-aware.
     *
     * @var array<string, string>
     */
    private array $terminals;

    /**
     * @param  list<string>  $customTerminals  extra paginating terminals (length-aware), e.g. `paginateList`
     */
    public function __construct(
        public readonly QueryBuilderFacts $facts = new QueryBuilderFacts,
        array $customTerminals = [],
    ) {
        $terminals = [
            'paginate' => 'length',
            'simplePaginate' => 'simple',
            'cursorPaginate' => 'cursor',
        ];
        foreach ($customTerminals as $terminal) {
            $terminals[$terminal] ??= 'length';
        }
        $this->terminals = $terminals;
    }

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $this->visitMethodCall($node, $node->name->toString(), $scope);
        }

        // Descend into any app-code call so allow-lists built inside a helper are reached; the engine
        // declines vendor / magic / over-budget descent on its own (Spike B split).
        return $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall;
    }

    private function visitMethodCall(Node\Expr\MethodCall $node, string $name, TypeScope $scope): void
    {
        if (! $this->receiverIsBuilder($node->var, $scope)) {
            return;
        }

        if (isset(self::CONFIG_METHODS[$name])) {
            [$bucket, $defaultKind] = self::CONFIG_METHODS[$name];
            $this->harvest($node, $scope, $bucket, $defaultKind, $name);
        }

        if (isset($this->terminals[$name])) {
            $this->recordTerminal($node, $name, $scope);
        }
    }

    private function harvest(Node\Expr\MethodCall $node, TypeScope $scope, string $bucket, string $defaultKind, string $method): void
    {
        foreach ($node->getArgs() as $arg) {
            $value = $arg->value;
            if ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $item) {
                    $this->collect($scope->constantValueOf($item->value), $bucket, $defaultKind, $method, $scope, $item->value);
                }

                continue;
            }

            $this->collect($scope->constantValueOf($value), $bucket, $defaultKind, $method, $scope, $value);
        }
    }

    /**
     * Fold one recovered constant into an allow-list entry, or record it unresolved.
     */
    private function collect(?ConstValue $value, string $bucket, string $defaultKind, string $method, TypeScope $scope, Node\Expr $expr): void
    {
        $entry = $value === null ? null : $this->entryFor($value, $defaultKind);
        if ($entry === null) {
            $location = $scope->location($expr);
            $this->facts->unresolved[] = sprintf('%s entry at %s:%d', $method, $location->file, $location->line);

            return;
        }

        if ($bucket === 'defaultSorts') {
            $this->facts->defaultSorts[] = $entry->name;

            return;
        }

        match ($bucket) {
            'filters' => $this->facts->filters[] = $entry,
            'sorts' => $this->facts->sorts[] = $entry,
            'includes' => $this->facts->includes[] = $entry,
            'fields' => $this->facts->fields[] = $entry,
            default => null,
        };
    }

    private function entryFor(ConstValue $value, string $defaultKind): ?QbEntry
    {
        if ($value->isScalar() && is_string($value->scalar)) {
            return new QbEntry($value->scalar, $defaultKind);
        }

        if ($value->isDescriptor()) {
            $name = $value->args[0] ?? null;
            if ($name instanceof ConstValue && $name->isScalar() && is_string($name->scalar)) {
                return new QbEntry($name->scalar, self::factoryMethod((string) $value->factory));
            }
        }

        return null;
    }

    private function recordTerminal(Node\Expr\MethodCall $node, string $name, TypeScope $scope): void
    {
        // The outermost terminal is the first one recorded (the engine walks the entry method fully
        // before descending), so per-page comes from the shallowest call site (design §4).
        if ($this->facts->paginates) {
            return;
        }

        $this->facts->paginates = true;
        $this->facts->paginationKind = $this->terminals[$name];

        $args = $node->getArgs();
        if (isset($args[0])) {
            $value = $scope->constantValueOf($args[0]->value);
            if ($value !== null && $value->isScalar() && is_int($value->scalar)) {
                $this->facts->perPage = $value->scalar;
            }
        }
    }

    private function receiverIsBuilder(Node\Expr $receiver, TypeScope $scope): bool
    {
        $type = $scope->typeOf($receiver);

        return $type instanceof ClassT && is_a($type->fqcn, self::QUERY_BUILDER, true);
    }

    /** The `method` segment of a `Class::method` factory FQCN. */
    private static function factoryMethod(string $factory): string
    {
        $sep = strpos($factory, '::');

        return $sep === false ? $factory : substr($factory, $sep + 2);
    }
}

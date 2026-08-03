<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\JsonApiPaginate;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;

/**
 * Recovers `spatie/laravel-json-api-paginate`'s `jsonPaginate()` terminal off any query-builder
 * receiver at any chain depth — the same trace-boundary descent the Query-Builder integration uses,
 * so a terminal reached through a two-deep list helper is still found. The macro is registered on the
 * Eloquent builder, the base query builder, the JSON:API relations and (transitively) Spatie's Query
 * Builder, so the receiver is matched against {@see BUILDERS}; a `jsonPaginate` call on anything else
 * is ignored (it is not this package's macro).
 *
 * The macro signature is `jsonPaginate(?int $maxResults, ?int $defaultSize, ?int $totalResults)`; the
 * first two arguments override config when a literal is present, folded from the OUTERMOST call site.
 */
final class JsonApiPaginateTraceVisitor implements TraceVisitor
{
    /**
     * The receiver base classes the macro is registered on (design: the package registers it on the
     * Eloquent + base builders and two relation types; Spatie's Query Builder forwards to them).
     *
     * @var list<string>
     */
    private const BUILDERS = [
        'Illuminate\\Database\\Eloquent\\Builder',
        'Illuminate\\Database\\Query\\Builder',
        'Illuminate\\Database\\Eloquent\\Relations\\Relation',
        'Spatie\\QueryBuilder\\QueryBuilder',
    ];

    public function __construct(
        public readonly JsonApiPaginateFacts $facts = new JsonApiPaginateFacts,
        private readonly string $methodName = 'jsonPaginate',
    ) {}

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        if ($node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === $this->methodName
        ) {
            $this->recordTerminal($node, $scope);
        }

        // Descend into any app-code call so a terminal built inside a helper is reached; the engine
        // declines vendor / magic / over-budget descent on its own (Spike B split).
        return $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall;
    }

    private function recordTerminal(Node\Expr\MethodCall $node, TypeScope $scope): void
    {
        if (! $this->receiverIsBuilder($node->var, $scope)) {
            return;
        }

        // The outermost terminal is recorded first (the engine walks the entry method before
        // descending), so overrides come from the shallowest call site.
        if ($this->facts->paginates) {
            return;
        }

        $this->facts->paginates = true;

        $args = $node->getArgs();
        $this->facts->maxResultsOverride = $this->intArg($args[0] ?? null, $scope);
        $this->facts->defaultSizeOverride = $this->intArg($args[1] ?? null, $scope);
    }

    private function intArg(?Node\Arg $arg, TypeScope $scope): ?int
    {
        if ($arg === null) {
            return null;
        }

        $value = $scope->constantValueOf($arg->value);

        return $value !== null && $value->isScalar() && is_int($value->scalar) ? $value->scalar : null;
    }

    private function receiverIsBuilder(Node\Expr $receiver, TypeScope $scope): bool
    {
        $type = $scope->typeOf($receiver);
        if (! $type instanceof ClassT) {
            return false;
        }

        foreach (self::BUILDERS as $builder) {
            if (is_a($type->fqcn, $builder, true)) {
                return true;
            }
        }

        return false;
    }
}

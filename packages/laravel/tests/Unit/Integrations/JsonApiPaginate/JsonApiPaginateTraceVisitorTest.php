<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateTraceVisitor;
use Docuccino\Laravel\Tests\Support\StubTraceScope;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * In-process proof of the jsonPaginate trace visitor over REAL php-parser nodes (a stub TypeScope
 * fixes the receiver type). Covers the builder-receiver mapping table entry-by-entry + the
 * non-builder degradation, the macro's `($maxResults, $defaultSize)` argument folding, a custom
 * method name, and detection through a helper call (the descent contract shared with the QB trace,
 * itself real-engine-proven).
 */
function traceJsonPaginate(string $chain, string $receiverFqcn, string $methodName = 'jsonPaginate'): JsonApiPaginateTraceVisitor
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse("<?php\n\$q = ".$chain.";\n") ?? [];

    $visitor = new JsonApiPaginateTraceVisitor(methodName: $methodName);
    $scope = new StubTraceScope(new ClassT($receiverFqcn));

    $traverser = new NodeTraverser(new class($visitor, $scope) extends NodeVisitorAbstract
    {
        public function __construct(private $visitor, private $scope) {}

        public function enterNode(Node $node)
        {
            if ($node instanceof Node\Expr) {
                $this->visitor->enterNode($node, $this->scope);
            }

            return null;
        }
    });
    $traverser->traverse($ast);

    return $visitor;
}

it('detects jsonPaginate on every builder receiver the macro is registered on', function (string $receiver): void {
    $facts = traceJsonPaginate('$query->jsonPaginate()', $receiver)->facts;

    expect($facts->paginates)->toBeTrue();
})->with([
    'eloquent builder' => ['Illuminate\\Database\\Eloquent\\Builder'],
    'base query builder' => ['Illuminate\\Database\\Query\\Builder'],
    'relation' => ['Illuminate\\Database\\Eloquent\\Relations\\Relation'],
    'spatie query builder' => ['Spatie\\QueryBuilder\\QueryBuilder'],
]);

it('ignores a jsonPaginate call on a non-builder receiver (unknown degradation)', function (): void {
    $facts = traceJsonPaginate('$service->jsonPaginate()', 'App\\Services\\ReportService')->facts;

    expect($facts->paginates)->toBeFalse();
});

it('folds the macro maxResults/defaultSize arguments from the call site', function (): void {
    $facts = traceJsonPaginate('$query->jsonPaginate(100, 10)', 'Illuminate\\Database\\Eloquent\\Builder')->facts;

    expect($facts->paginates)->toBeTrue()
        ->and($facts->maxResultsOverride)->toBe(100)
        ->and($facts->defaultSizeOverride)->toBe(10);
});

it('leaves the size overrides null when no literal arguments are given', function (): void {
    $facts = traceJsonPaginate('$query->jsonPaginate()', 'Illuminate\\Database\\Eloquent\\Builder')->facts;

    expect($facts->maxResultsOverride)->toBeNull()
        ->and($facts->defaultSizeOverride)->toBeNull();
});

it('recognises a configured custom macro name', function (): void {
    $facts = traceJsonPaginate('$query->apiPaginate()', 'Illuminate\\Database\\Eloquent\\Builder', 'apiPaginate')->facts;

    expect($facts->paginates)->toBeTrue();
});

it('detects the terminal reached through a query-building helper chain', function (): void {
    $facts = traceJsonPaginate('$query->where("active", true)->latest()->jsonPaginate(50)', 'Spatie\\QueryBuilder\\QueryBuilder')->facts;

    expect($facts->paginates)->toBeTrue()
        ->and($facts->maxResultsOverride)->toBe(50);
});

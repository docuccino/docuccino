<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\MethodReturnStatementsNode;

/**
 * Parses a file once and harvests its virtual `MethodReturnStatementsNode`s by method name — the node that
 * pairs every `return` with its flow-refined scope and carries the method's throw points. Memoised per file
 * so descent reuses one rich parse; the adapter's priming is what keeps the bodies from being stripped.
 *
 * @internal
 */
final class FileAnalyzer
{
    /** @var array<string, array<string, MethodReturnStatementsNode>> */
    private array $cache = [];

    /** @var array<string, array<int, ClosureReturnStatementsNode>> */
    private array $closureCache = [];

    /** @var array<string, array<string, array<string, Node\Expr\Array_>>> file → method → varName → first array-literal assigned */
    private array $arrayAssignmentCache = [];

    /** @var array<string, array<string, array<string, array{Node\Expr, Scope}|null>>> file → method → varName → its ONE assignment, or null when it takes several */
    private array $localAssignmentCache = [];

    public function __construct(private readonly RuntimeAdapter $adapter) {}

    /**
     * Every node this class hands out is consumed after its walk finished, so the scopes hanging off them
     * must be stabilised before they are queried — see {@see RuntimeAdapter::stableScope()}.
     */
    public function stableScope(Scope $scope): Scope
    {
        return $this->adapter->stableScope($scope);
    }

    /**
     * @return array<string, MethodReturnStatementsNode>
     */
    public function analyze(string $file): array
    {
        $normalised = $this->adapter->normalize($file);
        if (isset($this->cache[$normalised])) {
            return $this->cache[$normalised];
        }

        $collected = [];
        $this->adapter->processFile($file, static function (Node $node, Scope $scope) use (&$collected): void {
            // Watching for this virtual node is the sanctioned way to pair returns with refined scope.
            // @phpstan-ignore phpstanApi.instanceofAssumption
            if ($node instanceof MethodReturnStatementsNode) {
                $collected[$node->getMethodName()] = $node;
            }
        });

        return $this->cache[$normalised] = $collected;
    }

    /**
     * Keyed by start line — how an exception-handler render callback is located, since `ReflectionFunction`
     * gives us file+line and nothing else.
     *
     * @return array<int, ClosureReturnStatementsNode>
     */
    public function closures(string $file): array
    {
        $normalised = $this->adapter->normalize($file);
        if (isset($this->closureCache[$normalised])) {
            return $this->closureCache[$normalised];
        }

        $collected = [];
        $this->adapter->processFile($file, static function (Node $node, Scope $scope) use (&$collected): void {
            // @phpstan-ignore phpstanApi.instanceofAssumption
            if ($node instanceof ClosureReturnStatementsNode) {
                $collected[$node->getClosureExpr()->getStartLine()] = $node;
            }
        });

        return $this->closureCache[$normalised] = $collected;
    }

    /**
     * The file's `$var = [ ... ]` assignments by method then variable name, first assignment winning. Lets
     * the refiner recover provenance for a body built up in a local (`$body = [...]` then conditional
     * `$body[...] = …`) rather than written inline. The appends are ignored — the payload shape still comes
     * from PHPStan's inferred type of the variable at the return.
     *
     * @return array<string, array<string, Node\Expr\Array_>>
     */
    public function arrayAssignments(string $file): array
    {
        return $this->assignments($file)[0];
    }

    /**
     * Every local's assignment by method then variable name, as `[what was assigned, the scope it was
     * assigned in]` — and NULL for a local assigned more than once, which no one initialiser speaks for.
     * Lets the refiner follow a response built into a local and then returned back to the expression that
     * built it, whose shape the variable's own bare type has already thrown away.
     *
     * The scope is the one at the ASSIGNMENT, not at the return: an expression read in the wrong scope
     * binds whatever the arguments happen to hold later, which is how a shape stops being true.
     *
     * @return array<string, array<string, array{Node\Expr, Scope}|null>>
     */
    public function localAssignments(string $file): array
    {
        return $this->assignments($file)[1];
    }

    /**
     * Both assignment harvests off ONE walk of the file — the array-literal initialisers and every local's
     * single assignment. Memoised together because they read the same nodes; a second walk would cost a
     * full re-analysis of the file to collect what this one already saw.
     *
     * @return array{array<string, array<string, Node\Expr\Array_>>, array<string, array<string, array{Node\Expr, Scope}|null>>}
     */
    private function assignments(string $file): array
    {
        $normalised = $this->adapter->normalize($file);
        if (isset($this->arrayAssignmentCache[$normalised])) {
            return [$this->arrayAssignmentCache[$normalised], $this->localAssignmentCache[$normalised]];
        }

        /** @var array<string, array<string, Node\Expr\Array_>> $arrays */
        $arrays = [];
        /** @var array<string, array<string, array{Node\Expr, Scope}|null>> $locals */
        $locals = [];

        $this->adapter->processFile($file, static function (Node $node, Scope $scope) use (&$arrays, &$locals): void {
            // Every form that writes a local, not only the plain `=`: a guard that reads fewer shapes than
            // the language writes would serve one branch's expression for a variable `??=` or `.=` later
            // moved on. Only the plain `=` HAS an expression to serve; the rest merely retire one.
            if (! $node instanceof Node\Expr\Assign && ! $node instanceof Node\Expr\AssignRef && ! $node instanceof Node\Expr\AssignOp) {
                return;
            }

            if (! $node->var instanceof Node\Expr\Variable || ! is_string($node->var->name)) {
                return;
            }

            $method = $scope->getFunctionName();
            if ($method === null) {
                return;
            }

            $name = $node->var->name;

            // A second write retires the first: the variable at the return is whichever branch ran, and
            // picking one of them would publish a body the other branch never sends.
            $locals[$method][$name] = array_key_exists($name, $locals[$method] ?? []) || ! $node instanceof Node\Expr\Assign
                ? null
                : [$node->expr, $scope];

            if ($node instanceof Node\Expr\Assign && $node->expr instanceof Node\Expr\Array_) {
                // First assignment wins — the initialiser carries the provenance.
                $arrays[$method][$name] ??= $node->expr;
            }
        });

        $this->localAssignmentCache[$normalised] = $locals;

        return [$this->arrayAssignmentCache[$normalised] = $arrays, $locals];
    }
}

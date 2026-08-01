<?php

declare(strict_types=1);

namespace Docuccino\SpikeB;

use PhpParser\Node;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\File\FileHelper;
use PHPStan\Parser\Parser;
use PHPStan\Parser\PathRoutingParser;
use ReflectionMethod;
use Throwable;

/**
 * Prototype of the plan's `TypeEngine::trace(ActionRef, TraceVisitor)`.
 *
 * Drives an interprocedural, bounded, memoised, cycle-guarded walk starting from
 * an action method. For each method it analyses the file with the SAME PHPStan
 * machinery Spike A proved out (and re-primes the parser router per file — the
 * body-stripping trap), fires `TraceVisitor::enterNode(node, TypeScope)` for
 * every node, and descends into callees the visitor approves of (resolved to a
 * concrete file+method via reflection).
 *
 * Terminals are matched by NAME against the receiver — never descended into as
 * vendor code — while app-code helpers ARE descended, which is what recovers the
 * allowedFilters literals two calls deep.
 */
final class Tracer
{
    /** @var array<string, true> */
    private array $visited = [];

    /**
     * @param list<string> $terminals
     */
    public function __construct(
        private readonly NodeScopeResolver $nodeScopeResolver,
        private readonly ScopeFactory $scopeFactory,
        private readonly Parser $parser,
        private readonly FileHelper $fileHelper,
        private readonly PathRoutingParser $pathRoutingParser,
        private readonly TraceVisitor $visitor,
        private readonly TraceResult $result,
        private readonly int $maxDepth = 4,
        private readonly array $terminals = ['paginate', 'simplePaginate', 'cursorPaginate', 'paginateList'],
    ) {}

    public function trace(string $class, string $method, int $depth = 0, ?string $via = null): void
    {
        $key = $class.'::'.$method;
        if ($depth > $this->maxDepth || isset($this->visited[$key])) {
            return;
        }
        $this->visited[$key] = true;

        [$file, $isVendor] = $this->locate($class, $method);

        $this->result->chain[] = [
            'depth' => $depth,
            'class' => $class,
            'method' => $method,
            'via' => $via,
            'vendor' => $isVendor,
            'note' => $file === null ? '(unresolved)' : ($isVendor ? '(vendor — not descended)' : null),
        ];

        if ($file === null || $isVendor) {
            return;
        }

        /** @var list<array{callee: Callee, pos: int, via: string}> $descend */
        $descend = [];

        $this->analyseMethod($file, $class, $method, $depth, $descend);

        // Deterministic descent order: by source position of the call, first-seen wins.
        usort($descend, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
        $seen = [];
        foreach ($descend as $target) {
            $ck = $target['callee']->class.'::'.$target['callee']->method;
            if (isset($seen[$ck])) {
                continue;
            }
            $seen[$ck] = true;
            $this->trace($target['callee']->class, $target['callee']->method, $depth + 1, $target['via']);
        }
    }

    /**
     * @param list<array{callee: Callee, pos: int, via: string}> $descend
     */
    private function analyseMethod(string $file, string $class, string $method, int $depth, array &$descend): void
    {
        // THE SPIKE-A TRAP: prime the analysed set on BOTH the resolver and the
        // PathRoutingParser (normalised path) or method bodies get stripped and
        // the walk sees zero statements. Re-primed for every descended file.
        $normalised = $this->fileHelper->normalizePath($file);
        $this->pathRoutingParser->setAnalysedFiles([$normalised]);
        $this->nodeScopeResolver->setAnalysedFiles([$normalised]);

        $nodes = $this->parser->parseFile($file);
        $scope = $this->scopeFactory->create(ScopeContext::create($file));

        $callback = function (Node $node, Scope $rawScope) use ($class, $method, $depth, &$descend): void {
            // Only act on nodes lexically inside the target class method (skips
            // sibling methods + closures whose function name won't match).
            if ($rawScope->getClassReflection()?->getName() !== $class
                || $rawScope->getFunction()?->getName() !== $method
            ) {
                return;
            }

            $typeScope = new TypeScope($rawScope);
            $descendRequested = $this->visitor->enterNode($node, $typeScope);

            if (! $node instanceof Node\Expr\MethodCall && ! $node instanceof Node\Expr\StaticCall) {
                return;
            }

            $callee = CalleeResolver::resolve($node, $typeScope);
            $pos = $typeScope->location($node)['pos'];

            if ($descendRequested && $callee !== null && ! $callee->isVendor) {
                $descend[] = [
                    'callee' => $callee,
                    'pos' => $pos,
                    'via' => $this->describeCall($node),
                ];

                return;
            }

            // Matched-but-not-descended terminal (vendor/forwarded) → chain leaf,
            // proving the call graph reaches a paginating terminal.
            $name = $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall
                ? ($node->name instanceof Node\Identifier ? $node->name->toString() : null)
                : null;
            if ($name !== null && in_array($name, $this->terminals, true) && ! $descendRequested) {
                $receiver = $node instanceof Node\Expr\MethodCall
                    ? ($typeScope->objectClassOf($node->var) ?? '?')
                    : '?';
                $this->result->chain[] = [
                    'depth' => $depth + 1,
                    'class' => $receiver,
                    'method' => $name,
                    'via' => $this->describeCall($node),
                    'vendor' => true,
                    'note' => '(vendor/forwarded terminal — matched by name, not descended)',
                ];
            }
        };

        $this->nodeScopeResolver->processNodes($nodes, $scope, $callback);
    }

    /**
     * @return array{0: ?string, 1: bool} [file|null, isVendor]
     */
    private function locate(string $class, string $method): array
    {
        try {
            $rm = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return [null, false];
        }

        $file = $rm->getFileName();
        if ($file === false) {
            return [null, false];
        }

        return [$file, str_contains($file, '/vendor/')];
    }

    private function describeCall(Node $node): string
    {
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
            $parts = explode('\\', $node->class->toString());

            return end($parts).'::'.($node->name instanceof Node\Identifier ? $node->name->toString() : '?').'()';
        }

        if ($node instanceof Node\Expr\MethodCall) {
            return '->'.($node->name instanceof Node\Identifier ? $node->name->toString() : '?').'()';
        }

        return '?';
    }
}

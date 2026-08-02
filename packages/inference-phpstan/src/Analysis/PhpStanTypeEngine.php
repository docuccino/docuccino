<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TraceReport;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Support\Fqcn;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Throwing\ThrowAnalyzer;
use Docuccino\Inference\PhpStan\Trace\CalleeResolver;
use Docuccino\Inference\PhpStan\Trace\Tracer;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node\Expr\Variable;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Node\ReturnStatementsNode;
use Throwable;

/**
 * The single-process PHPStan/Larastan {@see TypeEngine} (Phase 2a). It harvests
 * `MethodReturnStatementsNode` for per-return-path types, runs the 3-layer
 * {@see ThrowAnalyzer}, and drives the interprocedural {@see Tracer}. Every
 * method is total: a per-action try/catch turns any failure into `UnknownT` + a
 * warning diagnostic rather than throwing (design §3).
 *
 * Not built here (Phase 2b): worker orchestration, recycling/bisection, the
 * engine result cache. The seams are present — `dependencyFiles` feeds the
 * cache key; the adapter is swappable per PHPStan minor — but no stubs.
 */
final class PhpStanTypeEngine implements TypeEngine
{
    /**
     * Per-build memo of callable analyses, keyed by {@see CallableRef::symbol()} (arch I1). A render
     * callback (or any callable) analysed once is reused across every route that reaches it — the
     * inferred-handler tier queries the same handler bodies for many routes.
     *
     * NOTE (deliberate deferral): under the worker orchestrator each worker holds its OWN memo, so a
     * callable analysed on two workers is analysed twice. A shared cross-worker callable cache is
     * deferred (see docs/plan.md, Phase 2b) — it needs the same serialize/transport plumbing as the
     * engine result cache and is not built here.
     *
     * @var array<string, ActionAnalysis>
     */
    private array $callableMemo = [];

    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly EngineConfig $config,
        private readonly TypeTranslator $translator,
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly ProjectFilter $projectFilter,
        private readonly ClassMetadataFactory $classMetadataFactory,
    ) {}

    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        try {
            return $this->doAnalyze($action);
        } catch (Throwable $e) {
            return new ActionAnalysis(
                returns: [new ReturnSite(
                    new UnknownT('analysis failed: '.$e->getMessage()),
                    new SourceLocation($action->file, $action->line),
                )],
                throws: [],
                diagnostics: [new Diagnostic(
                    Severity::Warning,
                    'inference.action-failed',
                    sprintf('Type analysis of %s failed: %s', $action->symbol(), $e->getMessage()),
                )],
                dependencyFiles: [$action->file],
            );
        }
    }

    private function doAnalyze(ActionRef $action): ActionAnalysis
    {
        $methods = $this->fileAnalyzer->analyze($action->file);
        $node = $methods[$action->method] ?? null;

        if (! $node instanceof MethodReturnStatementsNode) {
            return new ActionAnalysis(
                returns: [],
                throws: [],
                diagnostics: [new Diagnostic(
                    Severity::Warning,
                    'inference.method-not-found',
                    sprintf('No analysable method body for %s.', $action->symbol()),
                )],
                dependencyFiles: [$action->file],
            );
        }

        $returns = $this->harvestReturns($node, $action->file);

        $throwAnalyzer = $this->makeThrowAnalyzer();
        $throws = $throwAnalyzer->analyze($node, $this->selfLabel($action));

        $diagnostics = [];
        $dropped = $throwAnalyzer->droppedCount();
        if ($dropped > 0) {
            $diagnostics[] = new Diagnostic(
                Severity::Hint,
                'inference.throw-noise-dropped',
                sprintf('Dropped %d implicit "any-throwable" point(s) in %s.', $dropped, $action->symbol()),
            );
        }

        return new ActionAnalysis(
            returns: $returns,
            throws: $throws,
            diagnostics: $diagnostics,
            dependencyFiles: [$action->file, ...$throwAnalyzer->visitedFiles()],
        );
    }

    /**
     * @return list<ReturnSite>
     */
    private function harvestReturns(MethodReturnStatementsNode $node, string $file): array
    {
        $returns = [];
        foreach ($node->getReturnStatements() as $statement) {
            $returnNode = $statement->getReturnNode();
            $line = $returnNode->getStartLine();
            $location = new SourceLocation($file, $line);
            $expr = $returnNode->expr;

            if ($expr === null) {
                $returns[] = new ReturnSite(new VoidT, $location);

                continue;
            }

            $type = $statement->getScope()->getType($expr);
            $returns[] = new ReturnSite($this->translator->translate($type), $location);
        }

        return $returns;
    }

    public function analyzeCallable(CallableRef $callable): ActionAnalysis
    {
        return $this->callableMemo[$callable->symbol()] ??= $this->analyzeCallableUncached($callable);
    }

    private function analyzeCallableUncached(CallableRef $callable): ActionAnalysis
    {
        try {
            return $this->doAnalyzeCallable($callable);
        } catch (Throwable $e) {
            return new ActionAnalysis(
                diagnostics: [new Diagnostic(
                    Severity::Warning,
                    'inference.callable-failed',
                    sprintf('Analysis of %s failed: %s', $callable->symbol(), $e->getMessage()),
                )],
                dependencyFiles: [$callable->file],
            );
        }
    }

    private function doAnalyzeCallable(CallableRef $callable): ActionAnalysis
    {
        $method = $callable->method;
        $node = $method === null
            ? ($this->fileAnalyzer->closures($callable->file)[$callable->line] ?? null)
            : ($this->fileAnalyzer->analyze($callable->file)[$method] ?? null);

        if (! $node instanceof ReturnStatementsNode) {
            return new ActionAnalysis(
                diagnostics: [new Diagnostic(
                    Severity::Info,
                    'inference.callable-not-found',
                    sprintf('No analysable body for %s.', $callable->symbol()),
                )],
                dependencyFiles: [$callable->file],
            );
        }

        $narrowed = $this->harvestNarrowed($node, $callable);

        return new ActionAnalysis(
            returns: $narrowed['returns'],
            diagnostics: $narrowed['diagnostics'],
            dependencyFiles: [$callable->file],
        );
    }

    /**
     * Harvest a callable's return sites. With a narrowing request, each return is tagged with the
     * PHPStan-narrowed type of the caught variable at that return, and the site reachable when the
     * variable is the narrowed exception type is selected by SOURCE-ORDER-FIRST-MATCH (mirroring the
     * `if ($e instanceof X) return …;` / default control flow) — so a catch-all `render(Throwable $e)`
     * yields exactly the response for the requested exception type.
     *
     * Narrowing honesty (B2): when MORE THAN ONE return site is guard-satisfiable for the narrowed
     * type — the ambiguity a negated guard (`if (! ($e instanceof X)) …`) or overlapping `instanceof`
     * branches produce — the first is still chosen, but an info diagnostic is raised so the recovered
     * shape is not passed off as unambiguous.
     *
     * @return array{returns: list<ReturnSite>, diagnostics: list<Diagnostic>}
     */
    private function harvestNarrowed(ReturnStatementsNode $node, CallableRef $callable): array
    {
        $param = $callable->narrowParameter;
        $narrowTo = $callable->narrowType;

        /** @var list<array{line: int, site: ReturnSite, guard: list<string>}> $sites */
        $sites = [];
        foreach ($node->getReturnStatements() as $statement) {
            $returnNode = $statement->getReturnNode();
            $line = $returnNode->getStartLine();
            $expr = $returnNode->expr;
            $scope = $statement->getScope();

            $type = $expr === null ? new VoidT : $this->translator->translate($scope->getType($expr));

            $guard = [];
            if ($param !== null) {
                $guard = $this->classFqcns($this->translator->translate($scope->getType(new Variable($param))));
            }

            $sites[] = ['line' => $line, 'site' => new ReturnSite($type, new SourceLocation($callable->file, $line)), 'guard' => $guard];
        }

        if ($param === null || $narrowTo === null) {
            return ['returns' => array_map(static fn (array $s): ReturnSite => $s['site'], $sites), 'diagnostics' => []];
        }

        // Deterministic control-flow order, then every return whose caught-variable guard the narrowed
        // type satisfies (an empty/unclassed guard is the unconditional default branch).
        usort($sites, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        $satisfiable = array_values(array_filter(
            $sites,
            fn (array $candidate): bool => $this->guardSatisfies($candidate['guard'], $narrowTo),
        ));

        return [
            'returns' => $satisfiable === [] ? [] : [$satisfiable[0]['site']],
            'diagnostics' => $this->narrowingAmbiguity($satisfiable, $narrowTo, $param, $callable),
        ];
    }

    /**
     * The narrowing-honesty diagnostic (B2). The source-order-first-match is trustworthy when the
     * chosen return is an EXACT guard match for the narrowed type (`instanceof <exact>`), or when it
     * is the sole satisfiable branch — the ordinary sequential-`instanceof`-plus-default shape, where
     * the exact branch precedes the reachable-for-any default, is unambiguous. It is AMBIGUOUS when a
     * broad/negated guard (`if (! ($e instanceof X)) …`, matched only via a supertype) is chosen ahead
     * of a later exact match, or when two branches both match the type exactly; only then is an info
     * diagnostic raised, so the chosen shape is not passed off as unambiguous.
     *
     * @param  list<array{line: int, site: ReturnSite, guard: list<string>}>  $satisfiable
     * @return list<Diagnostic>
     */
    private function narrowingAmbiguity(array $satisfiable, string $narrowTo, string $param, CallableRef $callable): array
    {
        $chosen = $satisfiable[0] ?? null;
        if ($chosen === null) {
            return [];
        }

        $exactMatches = array_filter($satisfiable, static fn (array $s): bool => in_array($narrowTo, $s['guard'], true));
        $chosenIsExact = in_array($narrowTo, $chosen['guard'], true);

        // A broad guard shadowed a later exact match, or two branches claim the type exactly.
        $ambiguous = $chosenIsExact ? count($exactMatches) > 1 : $exactMatches !== [];
        if (! $ambiguous) {
            return [];
        }

        return [new Diagnostic(
            Severity::Info,
            'inference.ambiguous-narrowing',
            sprintf(
                'More than one return site is reachable when %s narrows to %s in %s; the first in source order was chosen and the recovered shape may be ambiguous.',
                '$'.$param,
                $narrowTo,
                $callable->symbol(),
            ),
        )];
    }

    /**
     * Whether a return guarded by `$guard` (the caught variable's narrowed class types) is reachable
     * when the caught variable is `$narrowTo`. Empty guard = the default branch (reachable for any).
     *
     * @param  list<string>  $guard
     */
    private function guardSatisfies(array $guard, string $narrowTo): bool
    {
        if ($guard === []) {
            return true;
        }

        foreach ($guard as $guardFqcn) {
            if ($narrowTo === $guardFqcn || is_a($narrowTo, $guardFqcn, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The concrete class FQCNs a DType carries (a single class, or the members of a union /
     * intersection) — the caught variable's narrowed type at a return site.
     *
     * @return list<string>
     */
    private function classFqcns(DType $type): array
    {
        if ($type instanceof ClassT) {
            return [$type->fqcn];
        }

        if ($type instanceof UnionT || $type instanceof IntersectionT) {
            $out = [];
            foreach ($type->members as $member) {
                $out = [...$out, ...$this->classFqcns($member)];
            }

            return $out;
        }

        return [];
    }

    public function classMetadata(ClassRef $class): ClassMetadata
    {
        return $this->classMetadataFactory->forClass($class);
    }

    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport
    {
        if ($action->class === null) {
            return new TraceReport([$action->file]);
        }

        $tracer = new Tracer(
            $this->adapter,
            $this->translator,
            $this->projectFilter,
            new CalleeResolver($this->adapter->reflectionProvider()),
            $visitor,
            $this->config->traceDepth,
            $this->config->fileBudget,
        );

        try {
            $tracer->run($action->class, $action->method, $action->file);
        } catch (Throwable) {
            // Trace is best-effort; the visitor keeps whatever it harvested and
            // the report still carries every file the walk reached before failing.
        }

        return new TraceReport($tracer->visitedFiles());
    }

    private function makeThrowAnalyzer(): ThrowAnalyzer
    {
        return new ThrowAnalyzer(
            $this->adapter->reflectionProvider(),
            $this->projectFilter,
            $this->fileAnalyzer,
            $this->config->knownThrowers,
            new CalleeResolver($this->adapter->reflectionProvider()),
            $this->config->throwDepth,
        );
    }

    private function selfLabel(ActionRef $action): string
    {
        $class = $action->class !== null
            ? Fqcn::short($action->class)
            : basename($action->file, '.php');

        return $class.'::'.$action->method;
    }
}

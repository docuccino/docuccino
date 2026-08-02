<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
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
use PHPStan\Node\MethodReturnStatementsNode;
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

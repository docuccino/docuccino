<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Support;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\TraceReport;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;

/**
 * A deterministic, in-process {@see TypeEngine} for unit tests: it answers `analyzeAction`
 * and `classMetadata` from canned maps keyed by action symbol / class FQCN, and returns an
 * empty analysis / metadata for anything unknown. No PHPStan involved.
 */
final class StubTypeEngine implements TypeEngine
{
    /**
     * @param  array<string, ActionAnalysis>  $analyses  keyed by ActionRef::symbol()
     * @param  array<string, ClassMetadata>  $classes  keyed by FQCN
     */
    public function __construct(
        private array $analyses = [],
        private array $classes = [],
    ) {}

    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        return $this->analyses[$action->symbol()] ?? new ActionAnalysis(dependencyFiles: [$action->file]);
    }

    public function classMetadata(ClassRef $class): ClassMetadata
    {
        return $this->classes[$class->fqcn] ?? new ClassMetadata($class->fqcn);
    }

    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport
    {
        return new TraceReport;
    }
}

<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

/**
 * The framework-agnostic inference boundary (design §4). Implementations embed a
 * real type system (see `docuccino/inference-phpstan`) but expose only
 * serializable {@see DType\DType} results — never a PHPStan `Type` — so results
 * cross worker and cache boundaries unchanged.
 *
 * Every method is total: an implementation must never throw out of these calls.
 * On internal failure it returns a well-formed result carrying `UnknownT` and a
 * diagnostic. {@see NullTypeEngine} is the trivial totalising fallback.
 */
interface TypeEngine
{
    /** Analyse every return path + escaping exception of an action. */
    public function analyzeAction(ActionRef $action): ActionAnalysis;

    /** Expand a class's shape (properties, docblocks); lazy + memoised. */
    public function classMetadata(ClassRef $class): ClassMetadata;

    /**
     * Drive an interactive, bounded, interprocedural walk from an action.
     *
     * The visitor harvests as it walks; the returned {@see TraceReport} carries
     * the transitive dependency-file set the walk read, so callers can key a
     * fragment cache on it (a walk that descends N files deep must invalidate
     * when any of those files change).
     */
    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport;
}

<?php

declare(strict_types=1);

namespace Docuccino\SpikeB;

use PhpParser\Node;

/**
 * Prototype of the plan's:
 *
 *   interface TraceVisitor {
 *       public function enterNode(PhpParser\Node $n, TypeScope $s): bool; // true = descend into callee
 *   }
 *
 * The Tracer walks every node of the entered method with its flow-refined scope
 * and asks the visitor about each one. Returning true asks the Tracer to descend
 * into that node's callee (the Tracer owns the reflection + re-analysis plumbing;
 * the visitor owns the *semantics* of what is worth descending into). Harvesting
 * happens as a side effect inside enterNode, using the TypeScope.
 */
interface TraceVisitor
{
    public function enterNode(Node $node, TypeScope $scope): bool;
}

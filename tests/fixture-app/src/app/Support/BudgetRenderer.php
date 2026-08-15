<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Real-engine determinism-guard fixture for the refiner's memo-headroom rule. Two entry points reach the
 * SAME shared helper ({@see BudgetShared::make()}) through different-length hop chains, each hop in its
 * own file, so shrinking either bound — a per-analysis file budget of 2, or a descent depth of 2 —
 * truncates the deep path and leaves the direct one intact:
 *
 *   - {@see deep()}   → {@see BudgetPad::run()} → BudgetShared::make() → {@see BudgetLeaf::build()}
 *     (the bound is spent reaching BudgetShared, so the BudgetLeaf hop is cut off → make() truncated).
 *   - {@see direct()} → BudgetShared::make() → BudgetLeaf::build()
 *     (the bound reaches BudgetLeaf → make() recovers the full 418 shape).
 *
 * Both entry points go through ONE engine (see the refine-pair runner mode), in either order, and each
 * must answer the same either way: the truncated make() must never be memoised for the direct path, and
 * the complete one must never be served to the deep path, which had no headroom to compute it.
 */
final class BudgetRenderer
{
    public function deep(): JsonResponse
    {
        return BudgetPad::run();
    }

    public function direct(): JsonResponse
    {
        return BudgetShared::make();
    }
}

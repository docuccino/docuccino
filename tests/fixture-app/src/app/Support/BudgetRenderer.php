<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Real-engine determinism-guard fixture for the refiner's budget-truncation memo rule. Two entry points
 * reach the SAME shared helper ({@see BudgetShared::make()}) through different-length hop chains, each
 * hop in its own file so a per-analysis file budget of 2 truncates the deep path but not the direct one:
 *
 *   - {@see deep()}   → {@see BudgetPad::run()} → BudgetShared::make() → {@see BudgetLeaf::build()}
 *     (budget spent on BudgetPad + BudgetShared, so the BudgetLeaf hop is cut off → make() truncated).
 *   - {@see direct()} → BudgetShared::make() → BudgetLeaf::build()
 *     (budget reaches BudgetLeaf → make() recovers the full 418 shape).
 *
 * Analysed deep-first then direct in ONE engine (see the refine-pair runner mode), the direct path must
 * recover the full shape: the truncated make() from the deep path must NOT have been memoised and reused.
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

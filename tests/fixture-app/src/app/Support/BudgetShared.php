<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Real-engine determinism-guard fixture: the SHARED intermediate helper reached both deeply (via
 * {@see BudgetPad}, having already spent the file budget) and directly (with budget headroom). Its own
 * shape is only rich once the descent into {@see BudgetLeaf} succeeds — so under a tiny budget the deep
 * path recovers a TRUNCATED shape for `make()` and the direct path recovers the full 418 shape. The
 * refiner must never memoise the truncation and reuse it on the direct path (that would make the result
 * route-/worker-order dependent, breaking determinism).
 */
final class BudgetShared
{
    public static function make(): JsonResponse
    {
        return BudgetLeaf::build();
    }
}

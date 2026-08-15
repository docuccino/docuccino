<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Real-engine determinism-guard fixture: the SHARED intermediate helper reached both deeply (via
 * {@see BudgetPad}, having already spent the file budget) and directly (with budget headroom). Its own
 * shape is only rich once the descent into {@see BudgetLeaf} succeeds — so under a shrunken bound the deep
 * path recovers a TRUNCATED shape for `make()` and the direct path recovers the full 418 shape. Neither
 * may cross over: the truncation must not be memoised for the direct path, and the complete shape must not
 * be served to the deep path (either way the result would be route-order dependent).
 */
final class BudgetShared
{
    public static function make(): JsonResponse
    {
        return BudgetLeaf::build();
    }
}

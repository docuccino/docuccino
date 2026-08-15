<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Real-engine determinism-guard fixture: an extra hop that exists purely to SPEND one unit of the
 * per-analysis file budget (and one level of descent depth) before {@see BudgetShared} is reached, so the
 * descent into {@see BudgetLeaf} is cut off on the deep path. See {@see BudgetRenderer}.
 */
final class BudgetPad
{
    public static function run(): JsonResponse
    {
        return BudgetShared::make();
    }
}

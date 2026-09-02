<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Phase-4b real-engine fixture: per-exception render-callback closures, as an app registers with
 * `$exceptions->render(fn (OutOfStockException $e) => …)`. The inferred-handler engine analyses each
 * closure by file+line (from `ReflectionFunction`) and recovers its folded status + payload shape.
 */
class RenderCallbacks
{
    public function outOfStock(): callable
    {
        return function (OutOfStockException $e): JsonResponse {
            return response()->json([
                'error' => 'out_of_stock',
                'detail' => $e->getMessage(),
            ], 409);
        };
    }

    /**
     * Two callbacks written on ONE line, which is all `ReflectionFunction` reports about either of them:
     * the same file and the same line. Neither can be told from the other, so the tier documents nothing
     * for that line rather than one renderer's response for the other's exception.
     *
     * @return list<callable>
     */
    public function pair(): array
    {
        return [function (OutOfStockException $e): JsonResponse { return response()->json(['error' => 'out_of_stock'], 409); }, function (OrderConflictException $e): JsonResponse { return response()->json(['error' => 'conflict'], 423); }];
    }
}

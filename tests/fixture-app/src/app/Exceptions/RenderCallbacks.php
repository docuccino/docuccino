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

    /** The same renderer written as an arrow function, whose one implicit return is its body. */
    public function outOfStockArrow(): callable
    {
        return fn (OutOfStockException $e): JsonResponse => response()->json(['error' => 'out_of_stock'], 409);
    }

    /** A catch-all spelling its two answers as a ternary on the exception. */
    public function conflictTernary(): callable
    {
        return fn (\Throwable $e): ?JsonResponse => $e instanceof OrderConflictException ? response()->json(['error' => 'conflict'], 423) : null;
    }

    /** A catch-all that swaps a conflict for a generic error before building its one response from it. */
    public function conflictRebound(): callable
    {
        return function (\Throwable $e): JsonResponse {
            if ($e instanceof OrderConflictException) {
                $e = new \RuntimeException('The order changed while you were editing it.');
            }

            return response()->json(['error' => $e->getMessage()], 409);
        };
    }
}

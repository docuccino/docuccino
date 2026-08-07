<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Real-engine determinism-guard fixture (leaf of the {@see BudgetRenderer} helper chain). Its declared
 * bare `JsonResponse` return erases the payload/status generic, so the refiner only recovers the shape by
 * descending into this `new JsonResponse(...)`. Living in its OWN file makes it the fourth distinct file
 * a two-hop descent must touch — the hop that a deliberately tiny per-analysis file budget cuts off.
 */
final class BudgetLeaf
{
    public static function build(): JsonResponse
    {
        return new JsonResponse(['ok' => true, 'code' => 418], 418, ['Content-Type' => 'application/json']);
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Phase-4b real-engine fixture: a catch-all renderer registered as an INVOKABLE OBJECT
 * (`$exceptions->render(new InvokableProblemRenderer)`) — the shape Laravel wraps via
 * `Closure::fromCallable()`, so the inferred-handler tier must analyse `__invoke` as the real method it
 * is (its declaration line is not a closure literal). It emits an `application/problem+json`-style body
 * and branches on `instanceof`, so the engine recovers the folded status + payload shape per thrown type
 * with `$e` narrowed. Deliberately a DISTINCT shape from {@see ProblemRenderer} (an `instance` member,
 * different statuses) so a test cannot pass by coincidence.
 */
class InvokableProblemRenderer
{
    public function __invoke(Throwable $e): JsonResponse
    {
        if ($e instanceof OutOfStockException) {
            return response()->json([
                'type' => 'https://example.test/problems/out-of-stock',
                'title' => 'Conflict',
                'status' => 409,
                'instance' => '/orders',
            ], 409);
        }

        if ($e instanceof AuthenticationException) {
            return response()->json([
                'type' => 'https://example.test/problems/unauthenticated',
                'title' => 'Unauthorized',
                'status' => 401,
                'instance' => '/orders',
            ], 401);
        }

        return response()->json([
            'type' => 'about:blank',
            'title' => 'Server Error',
            'status' => 500,
            'instance' => '/orders',
        ], 500);
    }
}

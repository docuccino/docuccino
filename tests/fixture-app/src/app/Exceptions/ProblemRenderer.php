<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

// OutOfStockException is reused for the ambiguous-narrowing fixture below.

/**
 * A Problem-Details-style catch-all renderer, the shared-renderer pattern. The engine analyses
 * `render(Throwable $e)` once per thrown type with `$e` narrowed, so `instanceof` narrowing selects
 * the one reachable branch and the status + payload are recovered per exception.
 */
class ProblemRenderer
{
    public function render(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Unprocessable Entity',
                'status' => 422,
                'errors' => $e->errors(),
            ], 422);
        }

        if ($e instanceof AuthenticationException) {
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Unauthorized',
                'status' => 401,
            ], 401);
        }

        return response()->json([
            'type' => 'about:blank',
            'title' => 'Server Error',
            'status' => 500,
        ], 500);
    }

    /**
     * An ambiguous renderer (B2 narrowing-honesty fixture): a NEGATED guard puts the broad default
     * branch ahead of the specific one, so the source-order-first-match picks the default even when
     * `$e` is narrowed to OutOfStockException — the ambiguity the honesty diagnostic must flag.
     */
    public function renderAmbiguous(Throwable $e): JsonResponse
    {
        if (! ($e instanceof OutOfStockException)) {
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Server Error',
                'status' => 500,
            ], 500);
        }

        return response()->json([
            'type' => 'about:blank',
            'title' => 'Conflict',
            'status' => 409,
        ], 409);
    }
}

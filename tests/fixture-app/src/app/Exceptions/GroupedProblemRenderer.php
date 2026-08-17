<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * The `match (true)` renderer that GROUPS exception types onto one arm — the commonest way a handler says
 * "these all mean the same thing to a client". An arm fires when ANY of its conditions holds, so each type
 * it lists has to reach that arm's body and no other's.
 *
 * The rest is the same grammar read the other ways round: an arm whose second condition says nothing about
 * the parameter is reachable by anything (`renderFatal`), one that requires both still requires both
 * (`renderGated`), and two arms build through a helper the class gets from a trait (`renderNamed`,
 * `renderPlain`).
 */
final class GroupedProblemRenderer
{
    use RendersGroupedProblems;

    public function __invoke(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof PortalRejectedException,
            $e instanceof PortalThrottledException => response()->json([
                'type' => 'https://portal.example/errors/submission',
                'title' => 'Submission refused',
                'status' => 422,
            ], 422),
            default => response()->json([
                'type' => 'about:blank',
                'title' => 'Server Error',
                'status' => 500,
            ], 500),
        };
    }

    public function renderFatal(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof PortalUnavailableException || $this->isFatal($e) => response()->json([
                'type' => 'https://portal.example/errors/unavailable',
                'title' => 'Service Unavailable',
                'status' => 503,
            ], 503),
            default => response()->json([
                'type' => 'about:blank',
                'title' => 'Server Error',
                'status' => 500,
            ], 500),
        };
    }

    public function renderGated(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof PortalException && $this->isFatal($e) => response()->json([
                'type' => 'https://portal.example/errors/offline',
                'title' => 'Portal Offline',
                'status' => 503,
            ], 503),
            default => response()->json([
                'type' => 'about:blank',
                'title' => 'Server Error',
                'status' => 500,
            ], 500),
        };
    }

    public function renderNamed(Throwable $e): JsonResponse
    {
        return $this->namedProblem([
            'type' => 'https://portal.example/errors/conflict',
            'title' => 'Conflict',
            'status' => 409,
            'detail' => $e->getMessage(),
        ], 409);
    }

    public function renderPlain(Throwable $e): JsonResponse
    {
        return $this->plainProblem([
            'type' => 'https://portal.example/errors/gone',
            'title' => 'Gone',
            'status' => 410,
            'detail' => $e->getMessage(),
        ], 410);
    }

    private function isFatal(Throwable $e): bool
    {
        return $e->getCode() >= 500;
    }
}

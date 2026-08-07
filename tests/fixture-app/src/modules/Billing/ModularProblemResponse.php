<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Exceptions\ProblemResponse;
use Illuminate\Http\JsonResponse;

/**
 * Real-engine fixture: an RFC-9457 problem-response factory living in a modular `Modules\…` PSR-4 root —
 * PRIMED as app source but OUTSIDE the descend scope throws/QB-trace use. Its declared bare `JsonResponse`
 * return erases the shape, exactly like {@see ProblemResponse}, so the refiner must
 * follow into this primed-but-not-descended module to recover the shape (the modular-monorepo layout a
 * large production Laravel app uses). Proves the refiner's containment gate is PRIME scope, not descend scope.
 */
final class ModularProblemResponse
{
    public static function make(string $type, int $status): JsonResponse
    {
        return new JsonResponse([
            'type' => $type,
            'status' => $status,
        ], $status, ['Content-Type' => 'application/problem+json']);
    }
}

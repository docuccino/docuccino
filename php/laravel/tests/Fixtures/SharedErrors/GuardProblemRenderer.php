<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * The commonest error renderer there is: one problem document, several reasons for it. Each arm answers
 * 403 with the same carrier and the same media type, and differs only in the words it fills in — which is
 * the difference between illustrations, not between contracts.
 */
final class GuardProblemRenderer
{
    public function __invoke(Throwable $e): ?JsonResponse
    {
        $problem = match (true) {
            $e instanceof TokenExpiredException => ['title' => 'Token expired', 'detail' => 'Refresh the token and retry.'],
            $e instanceof RoleMissingException => ['title' => 'Role missing', 'detail' => 'Ask an administrator for access.'],
            $e instanceof RegionBlockedException => ['title' => 'Region blocked', 'detail' => 'This endpoint is not served in your region.'],
            default => null,
        };

        return $problem === null
            ? null
            : response()->json($problem + ['type' => 'about:blank', 'status' => 403], 403, ['Content-Type' => 'application/problem+json']);
    }
}

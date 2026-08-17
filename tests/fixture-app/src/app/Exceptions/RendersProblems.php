<?php

declare(strict_types=1);

namespace App\Exceptions;

use Docuccino\Attributes\ErrorComponent;
use Illuminate\Http\JsonResponse;

/**
 * The house problem-document shape, shared by every renderer that extends it. Naming it here names every
 * body built through it that nothing nearer the answer named — which is the only thing a shared helper is
 * in a position to say.
 */
abstract class RendersProblems
{
    /**
     * @param  array<string, mixed>  $body
     */
    #[ErrorComponent('PortalProblem')]
    protected function problem(array $body, int $status): JsonResponse
    {
        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }
}

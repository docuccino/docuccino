<?php

declare(strict_types=1);

namespace App\Exceptions;

use Docuccino\Attributes\ErrorComponent;
use Illuminate\Http\JsonResponse;

/**
 * The house problem shape shared as a TRAIT rather than a base class — the other way an application puts
 * one response builder behind several renderers. PHP reports a trait-imported method as the using class's
 * own while its file stays the trait's, so both the name this declares and the absence of one on
 * `plainProblem()` are reachable only through this file.
 */
trait RendersGroupedProblems
{
    /**
     * @param  array<string, mixed>  $body
     */
    #[ErrorComponent('GroupedProblem')]
    protected function namedProblem(array $body, int $status): JsonResponse
    {
        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function plainProblem(array $body, int $status): JsonResponse
    {
        return new JsonResponse($body, $status);
    }
}

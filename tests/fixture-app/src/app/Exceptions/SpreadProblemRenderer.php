<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * A renderer that builds its problem factory's argument list first and spreads it in, the way a renderer
 * does once the arguments differ per branch and the call does not. Every member of the document reads one
 * of those parameters, so a build that reads the spread as "nothing was passed" deletes the whole body —
 * of a response that always carries it. Registered as `$exceptions->render(new SpreadProblemRenderer)`.
 */
final class SpreadProblemRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return DataProblemDocument::make(...$this->arguments($e, $request))->toProblemResponse($request);
    }

    /**
     * @return array{0: InvoiceProblem, 1: string, 2: Request}
     */
    private function arguments(Throwable $e, Request $request): array
    {
        return [InvoiceProblem::Unprocessable, $e->getMessage(), $request];
    }
}

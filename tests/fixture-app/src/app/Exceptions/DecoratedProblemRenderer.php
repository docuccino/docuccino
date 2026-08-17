<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * A renderer that DECORATES a response somebody else built, next to an inline renderer that builds one of
 * its own. Both methods are called `render`, and both live in this one file — so a local's assignment
 * recorded under a bare method name would serve the inline renderer's 418 body to the decorator, whose
 * response is whatever it was handed.
 */
class DecoratedProblemRenderer
{
    /** The response arrives already built; this arm only adds a header to it. */
    public function render(JsonResponse $response): JsonResponse
    {
        $response->headers->set('X-Rendered-By', self::class);

        return $response;
    }

    /** The fallback renderer, written inline because it is only ever used from here. */
    public function fallback(): object
    {
        return new class
        {
            public function render(Throwable $e): JsonResponse
            {
                $response = ProblemResponse::make('about:blank', 'Teapot', 418, $e->getMessage());

                return $response;
            }
        };
    }
}

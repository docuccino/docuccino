<?php

declare(strict_types=1);

namespace App\Problems;

use Illuminate\Http\JsonResponse;

/** The problem any error is sent as, which problems saying more about one family of error extend. */
class ErrorProblem extends RenderedProblem
{
    public static function from(JsonResponse $rendered): JsonResponse
    {
        return (new JsonResponse(new self($rendered), $rendered->getStatusCode(), $rendered->headers->all()))
            ->header('Content-Type', 'application/problem+json');
    }

    /** Sends the problem as whichever class it is called on. */
    public static function make(JsonResponse $rendered): JsonResponse
    {
        return (new JsonResponse(new static($rendered), $rendered->getStatusCode(), $rendered->headers->all()))
            ->header('Content-Type', 'application/problem+json');
    }
}

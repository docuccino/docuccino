<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * The renderer arms where the response local is REBUILT after it was first named — destructured, bound by a
 * `foreach`, or written by a callee through a reference. None of the three is an `=`, and all three replace
 * what the variable holds, so the first assignment's 418 body describes nothing that goes out.
 */
class RebuiltProblemRenderer
{
    /** Destructuring: the pair decides which body goes out, and the first assignment speaks for neither. */
    public function destructured(Throwable $e): JsonResponse
    {
        $response = ProblemResponse::make('about:blank', 'Teapot', 418, $e->getMessage());

        [$response, $logged] = $this->pair($e);

        $logged->headers->set('X-Logged', '1');

        return $response;
    }

    /** A `foreach` value binding, which is a write with no assignment expression anywhere in the AST. */
    public function iterated(Throwable $e): JsonResponse
    {
        $response = ProblemResponse::make('about:blank', 'Teapot', 418, $e->getMessage());

        foreach ($this->pair($e) as $response) {
            // Whichever one the loop stopped on is what goes out.
        }

        return $response;
    }

    /** A callee writing the local through a reference: nothing at this call site shows the write at all. */
    public function relabelled(Throwable $e): JsonResponse
    {
        $response = ProblemResponse::make('about:blank', 'Teapot', 418, $e->getMessage());

        $this->relabel($response);

        return $response;
    }

    /**
     * @return array{JsonResponse, JsonResponse}
     */
    private function pair(Throwable $e): array
    {
        return [
            ProblemResponse::validation($e->getMessage(), []),
            ProblemResponse::make('about:blank', 'Server Error', 500, $e->getMessage()),
        ];
    }

    private function relabel(JsonResponse &$response): void
    {
        $response = ProblemResponse::make('about:blank', 'Gone', 410, 'the resource has left');
    }
}

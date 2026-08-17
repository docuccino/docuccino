<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * A renderer that NAMES the response before it goes out, which is what every renderer does as soon as it
 * has to keep the protocol headers an exception carries — `Retry-After` on a 503, `Allow` on a 405. The
 * body is built by {@see ProblemResponse::make()}, whose declared bare `JsonResponse` erases it, so the
 * shape exists only in the expression the local was assigned: refining the variable's own type recovers
 * nothing at all, which is how an error response ends up with no body.
 *
 * The arms differ only in how the local is written:
 *   - the AuthenticationException arm returns the helper's call straight out (the control);
 *   - the HttpException arm assigns it ONCE and mutates the response before returning it;
 *   - the fallback assigns the same local in two branches, which neither expression describes — pinned as
 *     a refusal, since picking one would publish a body the other branch never sends.
 */
class HeaderPreservingRenderer
{
    public function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof AuthenticationException => ProblemResponse::make('about:blank', 'Unauthorized', 401, $e->getMessage()),
            $e instanceof HttpException => $this->withHeaders($e),
            default => $this->eitherWay($e),
        };
    }

    /** The exception's own headers copied onto the response, so the problem body keeps its protocol semantics. */
    private function withHeaders(HttpException $e): JsonResponse
    {
        $response = ProblemResponse::make('about:blank', 'Bad Request', 400, $e->getMessage());

        $headers = $e->getHeaders();

        if ($headers !== []) {
            $response->headers->add($headers);
        }

        return $response;
    }

    /** One local, two bodies: whichever branch ran is what the caller gets, and neither speaks for both. */
    private function eitherWay(Throwable $e): JsonResponse
    {
        $response = ProblemResponse::make('about:blank', 'Conflict', 409, $e->getMessage());

        if ($e->getCode() === 0) {
            $response = ProblemResponse::make('about:blank', 'Server Error', 500, $e->getMessage());
        }

        return $response;
    }
}

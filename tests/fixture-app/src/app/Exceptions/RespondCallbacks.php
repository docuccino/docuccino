<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Callbacks an application hands `$exceptions->respond()`: each receives the response the handler already
 * rendered, the exception, and the request, and returns the response actually sent. Analysed by file+line
 * like a render callback, with the exception parameter narrowed per thrown type.
 */
class RespondCallbacks
{
    /** The one-line idiom: reshape API errors, pass everything else through. */
    public function pathGated(): callable
    {
        return fn (Response $response, Throwable $e, Request $request): Response => $request->is('api/*') ? ProblemEnvelope::from($response, $e) : $response;
    }

    /** The same contract spelled as an early return. */
    public function earlyReturn(): callable
    {
        return function (Response $response, Throwable $e, Request $request): Response {
            if (! $request->is('api/*')) {
                return $response;
            }

            return ProblemEnvelope::from($response, $e);
        };
    }

    /** Every error reshaped, unconditionally. */
    public function everywhere(): callable
    {
        return fn (Response $response, Throwable $e): Response => ProblemEnvelope::from($response, $e);
    }

    /** A branch on the exception inside the callback itself. */
    public function perException(): callable
    {
        return function (Response $response, Throwable $e): Response {
            if ($e instanceof ValidationException) {
                return new JsonResponse(['message' => 'invalid', 'fields' => $e->errors()], 422);
            }

            return $response;
        };
    }

    /** One status redirected, every other response passed through. */
    public function statusGated(): callable
    {
        return function (Response $response): Response {
            if ($response->getStatusCode() === 419) {
                return back()->with(['message' => 'expired']);
            }

            return $response;
        };
    }

    /** The same branch as a one-line arrow function, with no exception parameter to narrow. */
    public function statusTernary(): callable
    {
        return fn (Response $response): Response => $response->getStatusCode() === 419 ? back() : $response;
    }

    /** Headers added, body untouched. */
    public function passThrough(): callable
    {
        return function (Response $response): Response {
            $response->headers->set('X-Error', 'true');

            return $response;
        };
    }

    /** The media type rewritten through the header bag: not the response it was handed. */
    public function retyped(): callable
    {
        return function (Response $response): Response {
            $response->headers->set('Content-Type', 'application/problem+json');

            return $response;
        };
    }

    /** Rewritten in place by a helper it is handed to: the response returned is not the one it was handed. */
    public function decoratedInPlace(): callable
    {
        return function (Response $response): Response {
            ProblemEnvelope::decorate($response);

            return $response;
        };
    }

    /** Reshaped where the helper can, handed back where it throws — by which time it may have written to it. */
    public function reshapedElseHandedBack(): callable
    {
        return function (Response $response, Throwable $e): Response {
            try {
                return ProblemEnvelope::from($response, $e);
            } catch (Throwable) {
                return $response;
            }
        };
    }

    /** A missing record reshaped by the class the handler converts it to before this is called. */
    public function notFoundReshaped(): callable
    {
        return function (Response $response, Throwable $e): Response {
            if ($e instanceof NotFoundHttpException) {
                return new JsonResponse(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404], 404, ['Content-Type' => ProblemEnvelope::CONTENT_TYPE]);
            }

            return $response;
        };
    }

    /** The same branch on the class thrown, which the handler has converted by the time this is called. */
    public function modelNotFoundReshaped(): callable
    {
        return function (Response $response, Throwable $e): Response {
            if ($e instanceof ModelNotFoundException) {
                return new JsonResponse(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404], 404, ['Content-Type' => ProblemEnvelope::CONTENT_TYPE]);
            }

            return $response;
        };
    }
}

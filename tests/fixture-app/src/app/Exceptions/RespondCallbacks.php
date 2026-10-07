<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Problems\HttpProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    /** Every JSON error reshaped, anything else — a view, a redirect, a download — passed through by its class. */
    public function jsonGuarded(): callable
    {
        return function (Response $response, Throwable $e, Request $request): Response {
            if (! $response instanceof JsonResponse) {
                return $response;
            }

            return ProblemEnvelope::from($response, $e);
        };
    }

    /** The same guard turned around: the rewrite inside it, the pass-through after. */
    public function jsonGuardedReversed(): callable
    {
        return function (Response $response, Throwable $e): Response {
            if ($response instanceof JsonResponse) {
                return ProblemEnvelope::from($response, $e);
            }

            return $response;
        };
    }

    /** The same guard as a one-line arrow function. */
    public function jsonGuardedTernary(): callable
    {
        return fn (Response $response, Throwable $e): Response => $response instanceof JsonResponse ? ProblemEnvelope::from($response, $e) : $response;
    }

    /** A JSON error rebuilt as problem+json into the variable it arrived in, which is then handed back. */
    public function jsonRebound(): callable
    {
        return function (Response $response): Response {
            if ($response instanceof JsonResponse) {
                $response = response($response->getContent(), $response->getStatusCode(), ['Content-Type' => ProblemEnvelope::CONTENT_TYPE]);
            }

            return $response;
        };
    }

    /** A redirect passed through by its class, negated in parentheses; everything else reshaped. */
    public function redirectGuarded(): callable
    {
        return function (Response $response, Throwable $e): Response {
            if (! ($response instanceof RedirectResponse)) {
                return ProblemEnvelope::from($response, $e);
            }

            return $response;
        };
    }

    /** The problem built as an object from the rendered response, sent with its status and headers. */
    public function problemObject(): callable
    {
        return function (Response $response, Throwable $e, Request $request): Response {
            if (! $request->is('api/*')) {
                return $response;
            }

            return (new JsonResponse(new HttpProblem($response), $response->getStatusCode(), $response->headers->all()))
                ->header('Content-Type', 'application/problem+json');
        };
    }

    /** The reason phrase read inline off the status it is sent with, through the subclass the table is inherited by. */
    public function statusTextInline(): callable
    {
        return fn (Response $response): Response => new JsonResponse([
            'title' => JsonResponse::$statusTexts[$response->getStatusCode()],
            'status' => $response->getStatusCode(),
        ], $response->getStatusCode());
    }

    /** A reason phrase looked up by a code that is not the status the response is sent with. */
    public function statusTextOtherKey(): callable
    {
        return fn (Response $response, Throwable $e): Response => new JsonResponse([
            'title' => Response::$statusTexts[$e->getCode()] ?? 'Error',
            'status' => $response->getStatusCode(),
        ], $response->getStatusCode());
    }

    /** The reason phrase of the status the response was built with, and then another status sent. */
    public function statusTextRestated(): callable
    {
        return fn (Response $response): Response => (new JsonResponse([
            'title' => Response::$statusTexts[$response->getStatusCode()] ?? 'Error',
            'status' => $response->getStatusCode(),
        ], $response->getStatusCode()))->setStatusCode(500);
    }
}

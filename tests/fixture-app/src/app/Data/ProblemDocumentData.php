<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * A Data class that is BOTH the runtime carrier and the documented schema of an error body — the shape an
 * app lands on once it wants one shared problem-details component instead of an array literal per branch.
 *
 * The renderer never touches a `JsonResponse` constructor: it hands back
 * `toProblemResponse()`, which renders through spatie (`withoutWrapping()->toResponse()`, mandatory when
 * `data.wrap` is set globally) and then re-labels the media type on the response it is about to return.
 * Both halves are opaque to a naive read — `toResponse()` declares a bare `JsonResponse`, and the media
 * type is a header mutation rather than a constructor argument. Only ever analysed, never dispatched.
 *
 * `instance` and `errors` are `Optional` because only some branches carry them, which is exactly why the
 * shared schema cannot say whether a given response has them: the deciding fact is which arguments that
 * branch passed to this constructor.
 */
class ProblemDocumentData extends Data
{
    /**
     * @param  list<string>|Optional  $errors
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public string $detail,
        public string|Optional $instance = new Optional,
        public array|Optional $errors = new Optional,
    ) {}

    public function toProblemResponse(Request $request): JsonResponse
    {
        $response = $this->withoutWrapping()->toResponse($request);

        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }

    /**
     * The same render, negotiated: two branches build into the SAME `$response` variable and only the
     * second labels its media type. Reading header writes by variable name alone would hand that label to
     * the plain branch's body, which is the one documented here (branch order decides).
     */
    public function toNegotiatedResponse(Request $request): JsonResponse
    {
        if ($request->query('plain') !== null) {
            $response = $this->withoutWrapping()->toResponse($request);

            return $response;
        }

        $response = $this->withoutWrapping()->toResponse($request);

        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}

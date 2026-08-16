<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Data\ProblemDocumentData;
use App\Support\TraceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Spatie\LaravelData\Optional;

/**
 * Real-engine fixture for the refiner's remaining edge paths, each previously proven nowhere:
 *
 *   - {@see narrowedEnumVariable()} binds an enum VARIABLE that PHPStan has narrowed to a single case
 *     (rather than a `Enum::Case` const-fetch), exercising the second `enumCaseOf` path;
 *   - {@see unbindableOptionalMember()} writes a `?? new Optional` argument whose left side is a static
 *     read rather than a parameter, so no call site can decide whether the member is there;
 *   - {@see nullableOptionalMember()} passes a NULLABLE value into the same idiom one hop away, so the
 *     member renders on the runs where that value is there and is omitted on the rest;
 *   - {@see lowercaseContentType()} writes the header key in lower case, so the `Content-Type` lookup
 *     must match case-INSENSITIVELY;
 *   - {@see noHeaders()} omits the headers argument entirely (content type absent → default);
 *   - {@see cyclicA()}/{@see cyclicB()} are mutually recursive, so the descent must hit the cycle guard
 *     and decline rather than recurse forever.
 */
final class RefinerEdgeCases
{
    /**
     * The argument is a VARIABLE, not `InvoiceProblem::Conflict` written at the call site — the
     * comparison narrows its type to exactly one case, which is what the refiner must read off the
     * scope to fold the accessors (409/Conflict).
     */
    public function narrowedEnumVariable(InvoiceProblem $problem): JsonResponse
    {
        // Guard by THROWING rather than returning, so the narrowed call below is the method's only
        // response return site (the refiner folds the first documentable return).
        if ($problem !== InvoiceProblem::Conflict) {
            throw new InvalidArgumentException('Only the Conflict case is rendered here.');
        }

        // $problem is now narrowed to the single case InvoiceProblem::Conflict.
        return ProblemResponse::fromProblem($problem, 'Narrowed from a variable.');
    }

    /**
     * Passes a NON-NULL `$data` so `fromProblem()`'s conditional `$body['data'] = $data` append is live:
     * the appended member must appear in the recovered shape (widened, since its value does not fold).
     * Every other caller passes null, leaving that arm unexercised.
     */
    public function conditionalAppend(): JsonResponse
    {
        return ProblemResponse::fromProblem(InvoiceProblem::Forbidden, 'With data.', ['ref' => 'abc']);
    }

    /**
     * `instance` is written as `TraceContext::id() ?? new Optional` — the idiom for "absent unless the
     * trace context has one", the same shape as an app's `Tracer::traceId() ?? new Optional`. Its left
     * side is a static read rather than a parameter, so no call site anywhere can settle whether this
     * response carries the member: it is supplied on one run and omitted on the next.
     */
    public function unbindableOptionalMember(Request $request): JsonResponse
    {
        return (new ProblemDocumentData(
            type: 'https://errors.test/problems/traced',
            title: 'Traced',
            status: 424,
            detail: 'Trace context decides one member.',
            instance: TraceContext::id() ?? new Optional,
        ))->toProblemResponse($request);
    }

    /**
     * The same `?? new Optional` idiom, reached the way an app actually reaches it: the factory writes
     * `errors: $errors ?? new Optional` against its own parameter, and this caller hands it a value that may
     * be null. So the body carries `errors` on the runs where the caller had some and omits the key on the
     * rest — one response, two shapes.
     *
     * @param  list<string>|null  $errors
     */
    public function nullableOptionalMember(Request $request, ?array $errors): JsonResponse
    {
        return DataProblemDocument::make(
            InvoiceProblem::Unprocessable,
            'The caller may or may not have field errors to report.',
            $request,
            errors: $errors,
        )->toProblemResponse($request);
    }

    /** A lower-case header key — the content-type match is case-insensitive. */
    public function lowercaseContentType(): JsonResponse
    {
        return new JsonResponse(['type' => 'https://errors.test/problems/lowercase'], 418, [
            'content-type' => 'application/problem+json',
        ]);
    }

    /** No headers argument at all — no explicit content type is recovered. */
    public function noHeaders(): JsonResponse
    {
        return new JsonResponse(['type' => 'https://errors.test/problems/bare'], 422);
    }

    /** Mutually recursive with {@see cyclicB()} — the descent must hit the cycle guard. */
    public function cyclicA(): JsonResponse
    {
        return $this->cyclicB();
    }

    public function cyclicB(): JsonResponse
    {
        return $this->cyclicA();
    }
}

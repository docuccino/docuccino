<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Real-engine fixture for the refiner's remaining edge paths, each previously proven nowhere:
 *
 *   - {@see narrowedEnumVariable()} binds an enum VARIABLE that PHPStan has narrowed to a single case
 *     (rather than a `Enum::Case` const-fetch), exercising the second `enumCaseOf` path;
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

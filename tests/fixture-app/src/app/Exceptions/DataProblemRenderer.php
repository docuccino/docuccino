<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Data\OwnResponseProblemData;
use App\Data\ProblemDocumentData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * A renderer that documents its errors through a spatie Data object instead of an array literal, so every
 * problem response shares one component. Registered as `$exceptions->render(new DataProblemRenderer)`.
 *
 * Two things here are invisible without help: `ProblemDocumentData::toResponse()` declares a bare
 * `JsonResponse`, and the `application/problem+json` label is a header mutation rather than a constructor
 * argument. Recovering the body means seeing through both — the payload via the Data object the response
 * carries, the media type via the header set on the variable being returned.
 *
 * The arms differ in how far the `new ProblemDocumentData(...)` sits from the response:
 *   - validation goes through {@see DataProblemDocument::make()}, so the constructor is a call hop away and
 *     its enum-accessor members only fold once the bound case reaches it. Both OPTIONAL members end up
 *     supplied — `instance` by the factory always, `errors` only because this arm passes it — which is the
 *     only evidence that this response carries either;
 *   - `$e instanceof HttpException` reaches the Data through TWO hops (arm → `renderHttpProblem()` →
 *     `problem()`), matching how a real renderer layers its branches, and supplies neither optional member;
 *   - the `ArithmeticError` arm writes a class constant named like a credential and renders through the
 *     negotiated helper, so it pins both refusals: no folded secret, and no label borrowed from the
 *     helper's other branch;
 *   - the `JsonException` arm writes the same credential behind a `??` default, the shape a guard that
 *     unwrapped only concatenation would fold straight through;
 *   - the fallback writes every argument as a literal at the call site.
 */
final class DataProblemRenderer
{
    /**
     * The key this app's support callbacks are signed with. A constant string like any other, so
     * it folds like any other — which is the hazard the refiner has to refuse, since a folded member is
     * published as an example and examples survive emit.
     */
    private const SUPPORT_API_KEY = 'fixture-api-key-not-real';

    /** Set per deployment to override the key above; null here, as it is in every environment but one. */
    private const SUPPORT_KEY_OVERRIDE = null;

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        if ($e instanceof ValidationException) {
            return DataProblemDocument::make(
                InvoiceProblem::Unprocessable,
                $e->getMessage(),
                $request,
                errors: array_keys($e->errors()),
            )->toProblemResponse($request);
        }

        if ($e instanceof HttpException) {
            return $this->renderHttpProblem($e, $request);
        }

        if ($e instanceof \RuntimeException) {
            // A Data class that writes its own response: the constructor's status and Content-Type must win
            // over anything spatie's own `toResponse()` would have said.
            return (new OwnResponseProblemData(type: 'about:blank', status: 503))->toResponse($request);
        }

        if ($e instanceof \ArithmeticError) {
            // Two things this arm alone proves: the `detail` is a class constant named like a credential, the
            // one constant the refiner must leave unfolded; and it renders through the negotiated helper,
            // whose plain branch carries no media-type label of its own.
            return (new ProblemDocumentData(
                type: 'about:blank',
                title: 'Error',
                status: 500,
                detail: self::SUPPORT_API_KEY,
            ))->toNegotiatedResponse($request);
        }

        if ($e instanceof \JsonException) {
            // The override constant is null, so PHPStan types `A ?? B` as B's own value and the credential
            // folds exactly as a bare literal would — a published `const` that survives OAS emission.
            return (new ProblemDocumentData(
                type: 'about:blank',
                title: 'Error',
                status: 500,
                detail: self::SUPPORT_KEY_OVERRIDE ?? self::SUPPORT_API_KEY,
            ))->toProblemResponse($request);
        }

        return $this->problem('about:blank', 'Internal Server Error', 500, 'Something went wrong.', $request);
    }

    private function renderHttpProblem(HttpException $e, Request $request): JsonResponse
    {
        return $this->problem(
            'https://httpstatuses.io/'.$e->getStatusCode(),
            'Error',
            $e->getStatusCode(),
            $e->getMessage(),
            $request,
        );
    }

    private function problem(string $type, string $title, int $status, string $detail, Request $request): JsonResponse
    {
        return (new ProblemDocumentData(
            type: $type,
            title: $title,
            status: $status,
            detail: $detail,
        ))->toProblemResponse($request);
    }
}

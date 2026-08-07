<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\Enums\FilterOperator;

/**
 * Phase-4b real-engine fixture: an RFC-9457-style Problem-Details factory (the shape a real app hides
 * behind a `ProblemDetailsResponse::make()` helper). Its declared bare `JsonResponse` return erases the
 * payload/status generic at every call site, so the inferred-handler tier only recovers the shape by
 * following the indirection into THIS method's own `new JsonResponse(...)` — the flagship capability.
 *
 * Exercised shapes: `application/problem+json` content type from the explicit header; status recovered
 * by binding the caller's literal argument to the `$status` parameter ({@see make()}); a distinct 422
 * body carrying a pointer-list `errors` member ({@see validation()}).
 */
final class ProblemResponse
{
    public static function make(string $type, string $title, int $status, string $detail): JsonResponse
    {
        return new JsonResponse([
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json']);
    }

    /**
     * The enum-driven factory (the Eos `ProblemDetailsResponse::make()` shape): the problem's type URI,
     * status and title all read off the bound {@see InvoiceProblem} case's accessors, and the body is
     * BUILT UP in a `$body` variable with a conditional `data` append before being handed to
     * `new JsonResponse($body, $problem->status(), …)`. When the call site binds a concrete case, the
     * accessors fold to per-case literals; the HTTP status folds from the same `status()` accessor.
     *
     * @param  array<string, scalar>|null  $data
     */
    public static function fromProblem(InvoiceProblem $problem, string $detail, ?array $data = null): JsonResponse
    {
        $body = [
            'type' => $problem->value,       // ->value: the backing URI (folds via reflection)
            'code' => $problem->name,        // ->name: the case name (folds via reflection)
            'title' => $problem->title(),    // match ($this) method (folds via body analysis)
            'status' => $problem->status(),  // match ($this) method — also drives the HTTP status
            'docs' => $problem->docsUrl(),   // plain constant return (folds)
            'kind' => $problem->classify(),  // computed body — stays permissive (never guessed)
            'detail' => $detail,             // a plain string parameter — dynamic per call
        ];

        if ($data !== null) {
            $body['data'] = $data;
        }

        return new JsonResponse($body, $problem->status(), [
            'Content-Type' => 'application/problem+json',
        ]);
    }

    /**
     * A VENDOR-enum factory: the parameter is a Spatie {@see FilterOperator} (vendor code). `->value` and
     * `->name` still fold (reflection is vendor-safe), but `isDynamic()` is a VENDOR method the folder must
     * NOT analyse — it stays permissive. Proves the project-only containment boundary for method folding.
     */
    public static function fromOperator(FilterOperator $operator): JsonResponse
    {
        return new JsonResponse([
            'operator' => $operator->value,      // vendor ->value: folds ('=')
            'label' => $operator->name,          // vendor ->name: folds ('EQUAL')
            'dynamic' => $operator->isDynamic(),  // vendor METHOD: never analysed → permissive
        ], 400, ['Content-Type' => 'application/problem+json']);
    }

    /**
     * @param  list<array{pointer: string, detail: string}>  $errors
     */
    public static function validation(string $detail, array $errors): JsonResponse
    {
        return new JsonResponse([
            'type' => 'https://errors.test/problems/validation',
            'title' => 'Unprocessable Entity',
            'status' => 422,
            'detail' => $detail,
            'errors' => $errors,
        ], 422, ['Content-Type' => 'application/problem+json']);
    }
}

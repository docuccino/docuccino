<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

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

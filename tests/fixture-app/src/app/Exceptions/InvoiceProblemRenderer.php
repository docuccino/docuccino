<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Phase-4b real-engine flagship fixture: an idiomatic invokable Problem-Details renderer registered as
 * `$exceptions->render(new InvoiceProblemRenderer)`. It builds every response through helper
 * INDIRECTION — a `match (true)` whose arms call private methods / a static
 * `ProblemResponse::make()`-style helper declared to return a bare `JsonResponse`. The inferred-handler
 * tier must follow that indirection to recover each arm's real status, payload shape and
 * `application/problem+json` content type (generic invoices/orders naming — NOT a copy of any real app).
 *
 * The arms deliberately exercise every refinement shape the engine must handle:
 *   - a ONE-hop helper call (409, arm → `ProblemResponse::make(...)`);
 *   - a TWO-hop chain (404, arm → `renderNotFound()` → `make(...)`);
 *   - the 422 branch adding a pointer-list `errors` member through a distinct helper;
 *   - an UNFOLDABLE status argument (`$e->getStatusCode()` through the helper — status stays permissive,
 *     payload + content type still recover);
 *   - a VENDOR producer (`JsonResponse::fromJsonString`) that must NOT be descended;
 *   - a DIRECT `new JsonResponse(...)` return (429, zero-hop constructor fold);
 *   - a per-type null arm (delegate to the framework) and a broad `return null` early-out (non-JSON).
 */
final class InvoiceProblemRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        // Non-JSON clients: hand back to the framework renderer. A broad early-out (not keyed on $e),
        // so it must NOT shadow the per-type response arms below.
        if (! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $e instanceof OrderConflictException => ProblemResponse::make(
                'https://errors.test/problems/conflict',
                'Conflict',
                409,
                $e->getMessage(),
            ),
            $e instanceof InvoiceNotFoundException => $this->renderNotFound($e, $request),
            $e instanceof ValidationException => $this->renderValidation($e, $request),
            $e instanceof HttpException => $this->renderHttp($e, $request),
            $e instanceof HttpResponseException => $this->renderFromVendor($e),
            $e instanceof RateLimitedException => new JsonResponse([
                'type' => 'https://errors.test/problems/rate-limited',
                'title' => 'Too Many Requests',
                'status' => 429,
                'detail' => 'Too many requests; slow down.',
            ], 429, ['Content-Type' => 'application/problem+json']),
            $e instanceof InvoiceDelegatedException => null,
            default => $this->renderServerError($e, $request),
        };
    }

    private function renderNotFound(InvoiceNotFoundException $e, Request $request): JsonResponse
    {
        return ProblemResponse::make('https://errors.test/problems/not-found', 'Not Found', 404, $e->getMessage());
    }

    private function renderValidation(ValidationException $e, Request $request): JsonResponse
    {
        /** @var array<string, string|list<string>> $errors */
        $errors = $e->errors();

        return ProblemResponse::validation($e->getMessage(), $this->pointers($errors));
    }

    private function renderHttp(HttpException $e, Request $request): JsonResponse
    {
        // $e->getStatusCode() does not constant-fold, so the status stays permissive and falls back to
        // the exception's own status hint; the payload shape + content type still recover.
        return ProblemResponse::make('about:blank', 'HTTP Error', $e->getStatusCode(), $e->getMessage());
    }

    private function renderFromVendor(HttpResponseException $e): JsonResponse
    {
        // The producing call is a VENDOR static — the refiner declines to descend past the project gate,
        // so the shape stays unrecovered rather than reaching into framework internals.
        return JsonResponse::fromJsonString('{"type":"about:blank"}');
    }

    private function renderServerError(Throwable $e, Request $request): JsonResponse
    {
        return ProblemResponse::make('about:blank', 'Server Error', 500, 'Unexpected error.');
    }

    /**
     * @param  array<string, string|list<string>>  $errors
     * @return list<array{pointer: string, detail: string}>
     */
    private function pointers(array $errors): array
    {
        $out = [];
        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $out[] = ['pointer' => '#/'.$field, 'detail' => $message];
            }
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use Docuccino\Attributes\ErrorComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * A renderer that dispatches on a base class plus a marker interface — one exception family, three
 * different bodies. Registered as `$exceptions->render(new PortalProblemRenderer)`.
 *
 * `PortalException` carries `#[ErrorComponent]`, and every arm here inherits it, so the class anchor alone
 * would put one name over three bodies. Each arm that answers with a body of its own says so on the method
 * that answers; `renderPortal()` does not, so the house name {@see RendersProblems::problem()} declares —
 * in a file this class never mentions — stands for it. That helper builds all three, which is why the
 * OUTERMOST declaring method wins: were it the innermost, the helper would name every arm.
 */
final class PortalProblemRenderer extends RendersProblems
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        if ($e instanceof PortalException && $e instanceof HasProblemFields) {
            return $this->renderRejection($e);
        }

        if ($e instanceof PortalException && $e instanceof HasRetryWindow) {
            return $this->renderThrottle($e);
        }

        if ($e instanceof PortalException) {
            return $this->renderPortal($e);
        }

        return null;
    }

    #[ErrorComponent('PortalRejection')]
    private function renderRejection(PortalException&HasProblemFields $e): JsonResponse
    {
        return $this->problem([
            'type' => 'https://portal.example/errors/rejected',
            'title' => 'Rejected',
            'status' => 422,
            'fields' => $e->fields(),
        ], 422);
    }

    #[ErrorComponent('PortalThrottle')]
    private function renderThrottle(PortalException&HasRetryWindow $e): JsonResponse
    {
        return $this->problem([
            'type' => 'https://portal.example/errors/throttled',
            'title' => 'Too Many Requests',
            'status' => 429,
            'retryAfter' => $e->retryAfter(),
        ], 429);
    }

    private function renderPortal(PortalException $e): JsonResponse
    {
        return $this->problem([
            'type' => 'https://portal.example/errors/unavailable',
            'title' => $e->getMessage(),
            'status' => 503,
        ], 503);
    }
}

<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Exceptions;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;

/**
 * The terminal {@see ExceptionToResponse} (design §6 chain, tier 3 placeholder): maps any
 * signalled exception with a status hint to a response carrying a generic `{message: string}`
 * body. The Problem Details preset and the inferred-handler analysis land in Phase 4; this keeps
 * error docs non-empty in the meantime. Pinned last so a specific mapper always wins first.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class DefaultExceptionToResponse implements ExceptionToResponse
{
    private const STATUS_TEXT = [
        400 => 'Bad Request',
        401 => 'Unauthenticated',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Server Error',
        503 => 'Service Unavailable',
    ];

    public function supports(ThrownException $exception, RouteContext $context): bool
    {
        return true;
    }

    public function toResponse(
        ThrownException $exception,
        RouteContext $context,
        ComponentRegistry $components,
    ): ResponseDraft {
        $status = $exception->httpStatusHint ?? 500;

        $draft = new ResponseDraft((string) $status);
        $contribution = Contribution::inference();

        $draft->setDescription(self::STATUS_TEXT[$status] ?? 'Error', $contribution);
        $draft->content('application/json')->set('type', 'object', $contribution);
        $draft->content('application/json')->set('properties', ['message' => ['type' => 'string']], $contribution);

        return $draft;
    }
}

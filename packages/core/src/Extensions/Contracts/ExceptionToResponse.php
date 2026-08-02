<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ThrownException;

/**
 * Maps an escaping exception to an error response (design §6). A chain: the first mapper whose
 * {@see supports()} is true and whose {@see toResponse()} is non-null wins; null defers.
 *
 * Deviation from the design sketch (`supports(DType, …)`): the engine already resolved each
 * throw to a {@see ThrownException} carrying both the FQCN and the constant-folded HTTP status
 * hint, so the contract operates on that richer value rather than re-deriving status from a
 * bare `DType`. The inferred-handler / preset tiers (design §6 chain) land in Phase 4.
 */
interface ExceptionToResponse
{
    public function supports(ThrownException $exception, RouteContext $context): bool;

    public function toResponse(
        ThrownException $exception,
        RouteContext $context,
        ComponentRegistry $components,
    ): ?ResponseDraft;
}

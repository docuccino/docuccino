<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\Unauthenticated;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;

/**
 * The Phase 3a security layer: `#[Unauthenticated]` marks an operation explicitly public by
 * clearing its security requirement (an empty `security: []` overrides any document default). The
 * full scheme set and middleware-driven auto-detection land in Phase 4.
 */
final class SecurityExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Security;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if ($context->attributes->has(Unauthenticated::class)) {
            $operation->setSecurity([], Contribution::attribute());
        }
    }
}

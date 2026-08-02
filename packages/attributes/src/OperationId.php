<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Overrides the human-readable operationId for an action or closure route.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class OperationId
{
    public function __construct(
        public string $id,
    ) {}
}

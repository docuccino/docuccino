<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Marks an operation (or every operation on a controller) as deprecated, with an optional reason.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class DeprecatedOperation
{
    public function __construct(
        public ?string $reason = null,
    ) {}
}

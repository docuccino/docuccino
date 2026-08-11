<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Describes a single enum case, surfaced as `x-enumDescriptions` on the enum schema.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class CaseDescription
{
    public function __construct(
        public string $description,
    ) {}
}

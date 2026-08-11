<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Sets a class's component display name (distinct from its diff identity).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SchemaName
{
    public function __construct(
        public string $name,
    ) {}
}

<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Loads a symbol-anchored markdown file into the description field of a controller, action or
 * property.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_PROPERTY)]
final readonly class DescriptionFromFile
{
    public function __construct(
        public string $path,
    ) {}
}

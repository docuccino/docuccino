<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Patches (or adds) a single property of an inferred request body. Repeatable; usable on
 * controllers, actions and closure routes.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class BodyParameter
{
    public function __construct(
        public string $name,
        public ?string $type = null,
        public ?string $description = null,
        public ?string $format = null,
        public bool $required = false,
        public mixed $example = null,
    ) {}
}

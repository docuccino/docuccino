<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Drops an inferred parameter by name (optionally scoped to a given `in` location). Repeatable;
 * usable on controllers, actions and closure routes.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class IgnoreParam
{
    public function __construct(
        public string $name,
        public ?string $in = null,
    ) {}
}

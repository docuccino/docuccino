<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Drops an inferred response by status code. Repeatable; usable on controllers, actions and
 * closure routes.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class IgnoreResponse
{
    public function __construct(
        public int $status,
    ) {}
}

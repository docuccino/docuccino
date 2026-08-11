<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Documents a response header on a given status. Repeatable; usable on controllers, actions and
 * closure routes.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class ResponseHeader
{
    public function __construct(
        public string $name,
        public ?string $type = null,
        public ?string $description = null,
        public int $status = 200,
    ) {}
}

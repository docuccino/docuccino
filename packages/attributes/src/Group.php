<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Assigns an operation to a tag (OAS group). Repeatable to place an operation under several tags;
 * usable on controllers, actions and closure routes.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Group
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {}
}

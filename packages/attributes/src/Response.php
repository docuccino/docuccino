<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Declares a response for an operation. Repeatable so one action can document several statuses;
 * usable on controllers, actions and closure routes.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Response
{
    public function __construct(
        public int $status = 200,
        public ?string $type = null,
        public ?string $description = null,
        public string $mediaType = 'application/json',
    ) {}
}

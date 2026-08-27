<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Documents a response header on a given status. Repeatable; usable on controllers, actions and
 * closure routes.
 *
 * `required` says the server sends the header on EVERY response at that status, which is what lets a
 * consumer type it non-optional and what a contract check holds the response to. It defaults to false
 * because a header nobody said anything about is one that may or may not arrive.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class ResponseHeader
{
    public function __construct(
        public string $name,
        public ?string $type = null,
        public ?string $description = null,
        public int $status = 200,
        public bool $required = false,
    ) {}
}

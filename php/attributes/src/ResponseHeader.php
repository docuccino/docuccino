<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Documents a response header on a given status. Repeatable; usable on controllers, actions and
 * closure routes.
 *
 * `name` and `status` say WHICH header this is; `type`, `description` and `required` say what it looks
 * like, and each of them is silent when it is not written. What the declaration states is contributed
 * and what it says nothing about is left to whatever documented the header already — a declaration is
 * routinely written to add one member to a header an integration recovered in full.
 *
 * `required` says the server sends the header on EVERY response at that status, which is what lets a
 * consumer type it non-optional and what a contract check holds the response to. `null` — the default —
 * says nothing, so a declaration written to add prose never de-requires a header the server always
 * sends; a written `true` or `false` is this layer's own statement about it.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class ResponseHeader
{
    public function __construct(
        public string $name,
        public ?string $type = null,
        public ?string $description = null,
        public int $status = 200,
        public ?bool $required = null,
    ) {}
}

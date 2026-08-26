<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Sets the `description` an API consumer reads, either inline or from a markdown file under the
 * application root.
 *
 * Exactly one of `text` and `file`: a declaration carrying both, or neither, says nothing certain and
 * is diagnosed rather than guessed at.
 *
 * `request` picks WHICH description an action-level declaration writes: the operation's by default,
 * the request body's when it is set. The two are different facts — how this endpoint behaves, and how
 * this endpoint wants the body filled in — so an action may carry one of each, which is why this is
 * repeatable. On a class or a property there is only ever one description, and `request` says nothing.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Description
{
    public function __construct(
        public ?string $text = null,
        public ?string $file = null,
        public bool $request = false,
    ) {}
}

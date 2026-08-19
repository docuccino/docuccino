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
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_PROPERTY)]
final readonly class Description
{
    public function __construct(
        public ?string $text = null,
        public ?string $file = null,
    ) {}
}

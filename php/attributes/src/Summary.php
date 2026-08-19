<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Sets the one-line `summary` an API consumer reads, so the docblock above the code is free to talk
 * to whoever maintains it instead.
 *
 * There is deliberately no `file:` form — a summary is one line, and a file for one line is ceremony.
 * Long prose is what `#[Description]` is for, and that one does read a file.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_PROPERTY)]
final readonly class Summary
{
    public function __construct(
        public string $text,
    ) {}
}

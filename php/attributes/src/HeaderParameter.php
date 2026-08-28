<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Documents a header parameter (patching or adding one). Repeatable; usable on controllers,
 * actions and closure routes.
 *
 * `$required` is three-valued — see {@see QueryParameter}.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class HeaderParameter
{
    public function __construct(
        public string $name,
        public ?string $type = null,
        public ?string $description = null,
        public ?string $format = null,
        public ?bool $required = null,
        public mixed $example = null,
    ) {}
}

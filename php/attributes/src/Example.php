<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Attaches an example (optionally named, or referenced via externalValue). Repeatable to supply
 * several named examples; usable on actions, DTO properties, parameters and closure routes.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_FUNCTION | Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final readonly class Example
{
    public function __construct(
        public mixed $value = null,
        public ?string $name = null,
        public ?string $summary = null,
        public ?string $externalValue = null,
    ) {}
}

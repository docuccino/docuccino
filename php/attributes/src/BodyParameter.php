<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Patches (or adds) a single property of an inferred request body. Repeatable; usable on
 * controllers, actions and closure routes.
 *
 * `$required` is three-valued on purpose: `null` — the default — says nothing, so a declaration written
 * to document a TYPE never de-requires a field the validation rules proved the server insists on, while
 * a written `true` or `false` is a statement the declaration's own layer makes about it.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class BodyParameter
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

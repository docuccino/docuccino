<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Documents a query parameter (patching or adding one). Repeatable; usable on controllers,
 * actions and closure routes.
 *
 * `$required` is three-valued on purpose, here and on the sibling parameter attributes: `null` — the
 * default — says nothing, so a declaration written to document a TYPE never de-requires a parameter an
 * integration proved the server insists on, while a written `true` or `false` is a statement this
 * declaration's own layer makes about it.
 *
 * `$name` may be left off only where the position supplies one — a Spatie Query Builder custom filter
 * class, whose parameter is named by its `AllowedFilter` registration. On an action it names nothing,
 * and is reported rather than applied.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class QueryParameter
{
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
        public ?string $description = null,
        public ?string $format = null,
        public ?bool $required = null,
        public mixed $default = null,
        public mixed $example = null,
    ) {}
}

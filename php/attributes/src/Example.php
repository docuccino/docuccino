<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Attaches an example (optionally named, or referenced via externalValue). Repeatable to supply
 * several named examples; usable on actions, DTO properties and closure routes.
 *
 * The value comes from exactly one of `value`, `file` (a JSON or YAML file under the app root) or
 * `externalValue`; `status`, `request` and `parameter` pick what it illustrates, at most one of them.
 *
 * There is deliberately no `TARGET_PARAMETER`, and a promoted constructor property still works
 * without it: PHP hangs a promoted property's attributes off both the parameter node and the
 * property node, and validates each against the target of the node it was reached through — so a
 * `ReflectionProperty` read, which is the only way anything here reads one, needs `TARGET_PROPERTY`
 * alone. Do not add it back for spatie Data classes; that path is `PROPERTY`.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Example
{
    public function __construct(
        public mixed $value = null,
        public ?string $name = null,
        public ?string $summary = null,
        public ?string $externalValue = null,
        public ?string $description = null,
        public ?string $file = null,
        public int|string|null $status = null,
        public ?string $mediaType = null,
        public ?string $parameter = null,
        public bool $request = false,
    ) {}
}

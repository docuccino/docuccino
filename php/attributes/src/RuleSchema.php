<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Documents a custom validation-rule class once, on the class itself: every field validated by an
 * instance of it picks these up. The attribute is the contract — any class can carry it, whether or
 * not it implements Laravel's `ValidationRule`. Each field maps onto the rule vocabulary
 * (`type` → a type rule, `enum` → `in:…`, `min`/`max` → the size rules, …).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RuleSchema
{
    /**
     * @param  ?string  $type  a JSON Schema type name (`string`, `integer`, `number`, `boolean`,
     *                         `array`), or any type rule name (`email`, `uuid`, …)
     * @param  ?list<string|int|float>  $enum  the allowed values
     */
    public function __construct(
        public ?string $type = null,
        public ?string $format = null,
        public ?string $pattern = null,
        public ?array $enum = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ?string $description = null,
        public string|int|float|bool|null $example = null,
    ) {}
}

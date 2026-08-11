<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Hides properties from a schema: on a property it drops that property; on a class it drops the
 * named properties (the Eloquent-model / DTO form).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final readonly class Hidden
{
    /**
     * @var list<string>
     */
    public array $properties;

    public function __construct(string ...$properties)
    {
        $this->properties = array_values($properties);
    }
}

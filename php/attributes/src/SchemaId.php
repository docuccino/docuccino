<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Pins a class's stable diff identity so renames do not break the schema `sch:` id.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SchemaId
{
    public function __construct(
        public string $id,
    ) {}
}

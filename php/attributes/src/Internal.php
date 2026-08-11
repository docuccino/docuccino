<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Marks an operation, controller or property as internal, surfaced as `x-internal: true`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_PROPERTY)]
final readonly class Internal {}

<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Marks an operation as requiring no authentication, clearing any inferred security requirement.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Unauthenticated {}

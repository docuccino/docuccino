<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Excludes a route (or every route on a controller) from documentation entirely.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class ExcludeFromDocs {}

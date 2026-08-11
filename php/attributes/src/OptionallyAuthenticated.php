<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Marks an operation as usable anonymously or authenticated. Security becomes an OR-list starting
 * with the empty requirement `{}`, followed by whatever was inferred from middleware or declared via
 * `#[Security]` — OAS's `security: [{}, …]` idiom for an endpoint that works without credentials but
 * recognises them when present.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class OptionallyAuthenticated {}

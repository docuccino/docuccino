<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Marks an operation as usable anonymously OR authenticated: its security becomes an OR-list whose
 * first alternative is the empty requirement `{}` (anonymous), followed by whatever requirement was
 * inferred (from middleware) or declared (via `#[Security]`). This is OAS's `security: [{}, …]`
 * idiom — the endpoint works without credentials but recognises them when present.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class OptionallyAuthenticated {}

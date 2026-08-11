<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Excludes a request-DTO / FormRequest property from the documented request body, leaving the
 * response schema alone. Use it for a field that genuinely shouldn't be sendable — a server-populated
 * value, say.
 *
 * Separate from {@see Hidden}, which hides from output, and the two stay separate on purpose: a
 * property hidden from output but still accepted in the request is a real shape, and one the
 * data-leakage lint exists to surface. Conflating them would suppress that signal.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class HiddenFromRequest {}

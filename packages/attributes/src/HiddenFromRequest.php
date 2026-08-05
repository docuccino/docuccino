<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Excludes a request-DTO / FormRequest property from the documented REQUEST body, without touching
 * the response schema.
 *
 * Deliberately distinct from {@see Hidden} (which hides from OUTPUT). The two are NOT conflated: a
 * property that is `#[Hidden]` from output but still accepted in the request is a real, intentional
 * shape — and one the data-leakage lint is designed to surface — so hiding it from the request too
 * would silently suppress that signal. Use this attribute when a property genuinely should not appear
 * as a sendable field (a server-populated value, or a Scramble request-`#[Hidden]` field being
 * migrated).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class HiddenFromRequest {}

<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Declares a security requirement for an operation, naming a scheme registered via
 * `security.schemes` config or an integration like Sanctum or Passport. Reach for it when middleware
 * detection can't see the requirement — a Gate/policy check, or a `tokenCan()` guard in the action.
 *
 * Repeatable, and each one is an alternative: stacking them gives an OR-list where any single
 * requirement satisfies the operation. Several scopes on one attribute is an all-of within that
 * scheme.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Security
{
    /**
     * @var list<string>
     */
    public array $scopes;

    /**
     * @param  string  $scheme  the security-scheme name this requirement references
     * @param  array<array-key, string>  $scopes  the scopes/abilities required against that scheme (all-of)
     */
    public function __construct(
        public string $scheme,
        array $scopes = [],
    ) {
        $this->scopes = array_values($scopes);
    }
}

<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Declares an explicit security requirement for an operation, referencing a security scheme by the
 * name it is registered under (via `security.schemes` config or an integration such as Sanctum or
 * Passport). Use it where middleware detection can't see the requirement — a Gate/policy check, or a
 * `$request->user()?->tokenCan()` guard in the action body.
 *
 * Repeatable: each `#[Security]` is one alternative, so stacking them models an OR-list of
 * requirements (`[{schemeA: scopesA}, {schemeB: scopesB}]` — any one satisfies the operation). A
 * single attribute with several scopes is an all-of within that one scheme.
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

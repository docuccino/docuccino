<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Declares the Sanctum token abilities an operation requires (all of them), for when the check lives
 * in the action body — `$request->user()->tokenCan('publish')` — rather than in `abilities:`
 * middleware. `sanctumToken` is an HTTP bearer scheme, so OAS can't carry abilities as scopes; the
 * Sanctum integration surfaces them as an `x-abilities` member plus a description line instead.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Abilities
{
    /**
     * @var list<string>
     */
    public array $abilities;

    public function __construct(string ...$abilities)
    {
        $this->abilities = array_values($abilities);
    }
}

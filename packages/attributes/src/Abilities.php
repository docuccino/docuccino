<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Declares the Sanctum token abilities an operation requires, for cases where the check lives in the
 * action body (`$request->user()->tokenCan('publish')`) rather than in `abilities:`/`ability:`
 * middleware. Because `sanctumToken` is an HTTP bearer scheme, OAS cannot carry abilities as scopes,
 * so the Sanctum integration surfaces them as an `x-abilities` extension member and a
 * "Requires token ability: …" description line. All listed abilities are required (all-of).
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

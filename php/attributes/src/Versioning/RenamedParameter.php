<?php

declare(strict_types=1);

namespace Docuccino\Attributes\Versioning;

use Attribute;

/**
 * Declares that a query, path, header or cookie parameter went by a different name in the versions
 * before the change's `since`. `$to` is the name in the code today and `$from` is the name older
 * documents publish, the same direction every other verb runs in.
 *
 * A parameter is not a schema property, which is why this names no class. It stands on the OPERATION —
 * `?page=` is a member of a request line, not of a body — so the parameter is addressed by where it
 * travels and what it is called, and there is nothing else about it a declaration could name. That
 * also means `#[AppliesTo]` behaves differently here: parameters are already per-operation, so a scope
 * narrows which operations are visited and never forks a shared shape.
 *
 * `$in` is one of `query`, `path`, `header` or `cookie`, matched case-insensitively. Anything else
 * names no location OpenAPI has, and is refused with a diagnostic rather than guessed at.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class RenamedParameter
{
    /**
     * @param  string  $in  where the parameter travels — `query`, `path`, `header` or `cookie`
     * @param  string  $from  the name versions before the change accept
     * @param  string  $to  the name in the code today
     */
    public function __construct(
        public string $in,
        public string $from,
        public string $to,
    ) {}
}

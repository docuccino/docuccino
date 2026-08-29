<?php

declare(strict_types=1);

namespace Docuccino\Attributes\Versioning;

use Attribute;

/**
 * Narrows an API version change to the operations it names. Written beside the change's
 * `#[ApiVersionChange]` rather than as an argument of every verb, so scope is declared once however
 * many fields the change renames, and a verb added later inherits scoping without re-implementing it.
 *
 * Absent, a change applies wherever the schema it names appears — the common case, because a shape
 * that changed changed wherever it is published. Present, only the operations matched by the
 * selectors: an operation signature (`GET /api/user`), an `operationId`, or either with `*` standing
 * for any run of characters (`GET /api/users/*`). Repeat the attribute to name more than one.
 *
 * @see ApiVersionChange
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class AppliesTo
{
    /**
     * @param  string  $operation  an operation signature, an operationId, or either with `*` wildcards
     */
    public function __construct(
        public string $operation,
    ) {}
}

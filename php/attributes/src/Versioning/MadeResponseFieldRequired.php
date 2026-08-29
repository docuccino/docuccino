<?php

declare(strict_types=1);

namespace Docuccino\Attributes\Versioning;

use Attribute;

/**
 * Declares that a response field of `$schema` became always-present in the change's version. The
 * versions before it published the field without promising it, so their documents leave `$field` out
 * of `required` while going on publishing the property itself.
 *
 * `$field` is the name the code spells today, like `RenamedResponseField`'s `to:`. Read the pair with
 * the direction the whole vocabulary runs in: the verb names what the change DID, and the older
 * document is what the code looks like with that undone.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class MadeResponseFieldRequired
{
    /**
     * @param  string  $schema  the class producing the response shape, as `WidgetResource::class`
     * @param  string  $field  the field name in the code today
     */
    public function __construct(
        public string $schema,
        public string $field,
    ) {}
}

<?php

declare(strict_types=1);

namespace Docuccino\Attributes\Versioning;

use Attribute;

/**
 * Declares that a response field of `$schema` is published under a different name by versions before
 * the change's `since`. `$to` is the name in the code today and `$from` is the name the older
 * document says; writing the pair the other way round renames the wrong end, and is the one mistake
 * this vocabulary invites.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class RenamedResponseField
{
    /**
     * @param  string  $schema  the class producing the response shape, as `WidgetResource::class`
     * @param  string  $from  the field name versions before the change publish
     * @param  string  $to  the field name in the code today
     */
    public function __construct(
        public string $schema,
        public string $from,
        public string $to,
    ) {}
}

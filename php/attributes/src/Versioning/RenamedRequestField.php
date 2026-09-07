<?php

declare(strict_types=1);

namespace Docuccino\Attributes\Versioning;

use Attribute;

/**
 * Declares that a request field of `$schema` is accepted under a different name by versions before the
 * change's `since`. `$to` is the name in the code today and `$from` is the name older documents
 * publish; writing the pair the other way round renames the wrong end, and is the one mistake this
 * vocabulary invites.
 *
 * `$schema` names the class the REQUEST body is recovered from — a form request, a Data class. That is
 * a different shape from the response one even where a single class produces both, and this verb
 * reaches only the request half; the response half has {@see RenamedResponseField}.
 *
 * A request rename is one of the few verbs a contract test can genuinely refuse: replay a request
 * spelling the field the old way with that version pinned, and the assertion says whether the
 * application really still accepts it.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class RenamedRequestField
{
    /**
     * @param  string  $schema  the class producing the request body, as `StoreWidgetRequest::class`
     * @param  string  $from  the field name versions before the change accept
     * @param  string  $to  the field name in the code today
     */
    public function __construct(
        public string $schema,
        public string $from,
        public string $to,
    ) {}
}

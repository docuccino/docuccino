<?php

declare(strict_types=1);

namespace Docuccino\Attributes\Versioning;

use Attribute;

/**
 * Declares that a request field of `$schema` became optional in the change's version. The versions
 * before it demanded the field, so their documents name `$field` in `required` and a request that
 * leaves it out is refused at that version — correctly, because that version really did demand it.
 *
 * `$schema` names the class the REQUEST body is recovered from — a form request, a Data class. That is
 * a different shape from the response one even where a single class produces both, and this verb
 * reaches only the request half; the response half has `MadeResponseFieldRequired` and
 * `MadeResponseFieldOptional`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class MadeRequestFieldOptional
{
    /**
     * @param  string  $schema  the class producing the request body, as `StoreWidgetRequest::class`
     * @param  string  $field  the field name in the code today
     */
    public function __construct(
        public string $schema,
        public string $field,
    ) {}
}

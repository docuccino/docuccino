<?php

declare(strict_types=1);

namespace Docuccino\Attributes\Versioning;

use Attribute;

/**
 * Declares that a response field of `$schema` became sometimes-absent in the change's version. The
 * versions before it always sent the field, so their documents name `$field` in `required`.
 *
 * This is the verb with a runtime half. An older document that promises the field is always there is
 * only true if the application really puts it back for a caller pinned that far — so pin the version
 * in a contract test and let the assertion say whether it does.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class MadeResponseFieldOptional
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

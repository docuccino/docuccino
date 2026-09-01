<?php

declare(strict_types=1);

namespace Docuccino\Attributes\Versioning;

use Attribute;

/**
 * Declares that a response field of `$schema` was REMOVED in the change's version, so the versions
 * before it published it and the documents derived for them put it back.
 *
 * This is the one verb whose fact is genuinely gone: every other verb names a field the code still
 * carries and moves what the document says ABOUT it, and there is nothing left in the code to read a
 * deleted field's type off. So `$field` is the name older versions published — not, as everywhere else
 * in this vocabulary, the name the code spells today — and `$type` is the shape, declared because it
 * cannot be recovered.
 *
 * `$type` is read three ways, in order: a class this document already publishes a response schema for
 * becomes a `$ref` to that component; one of OpenAPI's own type names — `string`, `integer`, `number`,
 * `boolean`, `object`, `array`, each optionally suffixed `[]` for a list of them and `?` for one that
 * may be null — becomes that `type`; and anything else publishes the field with no constraints at all
 * and says so with a diagnostic. Left empty it publishes the unconstrained shape and says nothing,
 * which is how to spell "it was there, and nobody now knows what it held".
 *
 * `$required` says the older versions always sent it. That makes their document STRICTER than today's,
 * which is exactly what the per-version contract test can refuse: pin the version, replay the suite,
 * and the assertion says whether the application really still sends the field to a caller pinned that
 * far back.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class RemovedResponseField
{
    /**
     * @param  string  $schema  the class producing the response shape, as `InvoiceResource::class`
     * @param  string  $field  the field name the versions before this change published
     * @param  string  $type  a published class, an OpenAPI type name, or empty for no shape at all
     * @param  bool  $required  whether those versions always sent it
     * @param  string  $description  what the field held, written for the consumer reading it
     */
    public function __construct(
        public string $schema,
        public string $field,
        public string $type = '',
        public bool $required = false,
        public string $description = '',
    ) {}
}

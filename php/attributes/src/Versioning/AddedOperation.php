<?php

declare(strict_types=1);

namespace Docuccino\Attributes\Versioning;

use Attribute;

/**
 * Declares that an operation was ADDED in the change's version, so the versions before it did not serve
 * it and the documents derived for them do not describe it at all.
 *
 * The cheapest verb in the vocabulary and one of the few that can be refused by a contract test: an
 * older document with no such operation is NARROWER than the code, so replaying a request to it with
 * that version pinned is an exchange the document does not describe.
 *
 * There is deliberately no opposite. Re-introducing an operation would mean declaring its parameters,
 * bodies, responses and security, none of which the code still carries — and unlike a field there is no
 * vague-but-true fallback to degrade to, because an operation with no documented responses is not vague,
 * it is broken.
 *
 * `$operation` carries its own selector rather than taking one from {@see AppliesTo}, because here the
 * operation is the SUBJECT rather than the place an edit lands. Write it the way the document names the
 * operation — the signature `POST /api/invoices`, an `operationId`, or either with `*` standing for any
 * run of characters. Repeat the attribute for more than one.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class AddedOperation
{
    /**
     * @param  string  $operation  an operation signature, an operationId, or either with `*` wildcards
     */
    public function __construct(
        public string $operation,
    ) {}
}

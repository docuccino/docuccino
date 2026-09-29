<?php

declare(strict_types=1);

namespace Docuccino\Attributes\Versioning;

use Attribute;

/**
 * Declares that `$value` was ADDED to the value set `$enum` publishes in the change's version, so the
 * versions before it never sent or accepted it and the documents derived for them do not list it.
 *
 * This is the direction of the pair that NARROWS an older document, which is the direction a contract
 * test can refuse: pin the version, replay the suite, and the assertion says whether the application
 * really keeps the value out of a response to a caller pinned that far back. Its sibling
 * {@see RemovedEnumValue} widens, and a document looser than the wire always passes.
 *
 * The value is written as the wire carries it — the backing value of a backed enum, matched exactly —
 * so an int-backed set takes `value: 3` and a string-backed one `value: 'draft'`. A value the set does
 * not publish is a declaration about a change nobody made, and says so rather than being applied.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class AddedEnumValue
{
    /**
     * @param  string  $enum  the enum class the document publishes the set for, as `Status::class`
     * @param  string|int  $value  the value this version added, as the wire carries it
     */
    public function __construct(
        public string $enum,
        public string|int $value,
    ) {}
}

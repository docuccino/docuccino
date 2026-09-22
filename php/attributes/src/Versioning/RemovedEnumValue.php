<?php

declare(strict_types=1);

namespace Docuccino\Attributes\Versioning;

use Attribute;

/**
 * Declares that `$value` was REMOVED from the value set `$enum` publishes in the change's version, so
 * the versions before it published it and the documents derived for them list it again.
 *
 * Like {@see RemovedResponseField}, this names something the code no longer carries — a deleted case
 * has no backing value left to read — so what the older set published is declared rather than
 * recovered. Unlike it, there is no shape to state: a value IS its own shape, and the set's `type`
 * already says what kind of value it is.
 *
 * `$name` is the SDK member name older generated clients knew the value by. Left empty it is minted
 * from the value itself, which is a pure function of that value and so renames no neighbour; state it
 * where the older clients' name is known and worth keeping. `$description` is the sentence the set's
 * per-value prose carried for it, and a set whose every other value has prose needs this to keep
 * publishing the map that Redoc and its kin read.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class RemovedEnumValue
{
    /**
     * @param  string  $enum  the enum class the document publishes the set for, as `Status::class`
     * @param  string|int  $value  the value the versions before this change published
     * @param  string  $name  the member name older clients knew it by, or empty to mint one
     * @param  string  $description  what the value meant, written for the consumer reading it
     */
    public function __construct(
        public string $enum,
        public string|int $value,
        public string $name = '',
        public string $description = '',
    ) {}
}

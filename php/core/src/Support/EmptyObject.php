<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use LogicException;
use stdClass;

/**
 * The empty JSON object, `{}`, as one shared immutable value.
 *
 * A document assembled as PHP arrays has no way of its own to say `{}` — `[]` is a list — so the
 * codebase says it with a {@see stdClass}, which every writer here already reads that way
 * ({@see CanonicalJsonSerializer}). Minting a fresh one per site made `{}` the one value in a draft
 * that is never `===` an equal `{}` beside it, and two things read a draft by identity: the patch
 * guard decides "these two producers agree" with `!==`, so two of them recorded a phantom `overrode`
 * entry against a value nobody lost, and a test comparing two whole builds cannot compare them at
 * all. Hence ONE instance per process, which every minting site takes instead of saying `new stdClass`
 * — the invariant is that the DRAFT is `===`-comparable; the canonicalised document mints its own and
 * never was.
 *
 * Extending stdClass is what keeps every `instanceof stdClass` reader matching unchanged, and it is
 * also what lets the guarantees be enforced rather than merely documented: the instance cannot be
 * cloned or written to, because a shared value one caller can mutate would put that caller's stray
 * member into every `{}` in the document. Identity is a within-process claim — two processes compare
 * documents by bytes, never by instance.
 *
 * @internal
 */
final class EmptyObject extends stdClass
{
    private static ?self $instance = null;

    public static function get(): self
    {
        return self::$instance ??= new self;
    }

    private function __construct() {}

    /** Blocked: a copy is a second `{}` that is not `===` the first, which is the whole defect. */
    private function __clone(): void {}

    /**
     * Blocked: this instance is every `{}` in the document, so one write reaches all of them. A
     * producer with a member to add has a populated object, which is a value of its own.
     */
    public function __set(string $name, mixed $value): never
    {
        throw new LogicException(sprintf('The shared empty JSON object is immutable; "%s" cannot be set on it.', $name));
    }
}

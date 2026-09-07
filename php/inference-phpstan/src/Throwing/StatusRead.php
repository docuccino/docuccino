<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

/**
 * The answer to "what HTTP status does this throw carry": the number the code states, or the record of
 * why none could be read ({@see UnreadStatus}).
 *
 * The two are one value because they are one fact, and this class is where that invariant is stated:
 * **a null status cannot exist without a record of why.** The constructor is private and neither named
 * constructor can build the pair any other way, so no path through the analysis can hand back "no
 * status" and leave nothing to report — which is the defect this shape exists to make unconstructible.
 * A null status is what an adapter keys at its own unplaced status — the key a response the document
 * cannot do without gets when nothing read one — so the condition that PUBLISHES such a response and the
 * condition the build can REPORT on are the same one by construction rather than by two readers agreeing.
 *
 * Whether the report is one a reader can act on is a separate question, answered later and off the file
 * the fold read ({@see UnreadStatus::isActionable()}). Silence there is a decision about the audience;
 * silence here would be a fact nobody recorded.
 *
 * @internal
 */
final readonly class StatusRead
{
    private function __construct(
        public ?int $status,
        public ?UnreadStatus $unread,
    ) {}

    /** A status the code states. */
    public static function of(int $status): self
    {
        return new self($status, null);
    }

    /** No status, and the record of which fold gave up on it. */
    public static function unread(UnreadStatus $unread): self
    {
        return new self(null, $unread);
    }

    /**
     * Whether this reading is one the document has no status key for, and so publishes under the
     * adapter's unplaced status. Stated as a question rather than left as `$status === null` at four
     * call sites, because it is the condition the reconciliation rests on.
     */
    public function isUnplaced(): bool
    {
        return $this->status === null;
    }
}

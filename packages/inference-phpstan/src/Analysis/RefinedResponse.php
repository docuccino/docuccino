<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnknownT;

/**
 * The response shape {@see ResponseShapeRefiner} recovered from a return expression that PHPStan had
 * erased to a bare `JsonResponse`/`Response` — a payload shape, a folded HTTP status, and any explicit
 * content type. It is emitted back as a `JsonResponse<payload, status, contentType>` {@see ClassT} the
 * inferred-handler pipeline already understands (the third arg is the refinement's addition; the
 * `response()->json()` extension emits only the first two).
 *
 * Two members carry recovery HONESTY (design goal: never guess):
 *   - {@see $statusParam} names the CALLEE parameter the status passes through unchanged, when the
 *     status is not a call-independent literal. It lets the CALL SITE bind a constant-foldable
 *     argument (`ProblemResponse::make($title, 422, $errors)`) into the status while keeping the
 *     callee analysis itself call-independent (so it memoises by callee symbol alone). A status that
 *     folds neither to a literal nor to a pass-through parameter stays permissive (both null).
 *   - {@see $delegates} marks a return that yielded no response at all (`return null` / void) — the
 *     "delegate to the framework" arm, which is neither a documentable response nor a fold failure.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final readonly class RefinedResponse
{
    public function __construct(
        public ?DType $payload = null,
        public ?LiteralT $status = null,
        public ?string $statusParam = null,
        public ?string $contentType = null,
        public bool $delegates = false,
    ) {}

    /** The "delegates to the framework" arm — a `return null` / void return, not a response. */
    public static function delegation(): self
    {
        return new self(delegates: true);
    }

    /** Whether anything documentable was recovered (a bare, everything-null shape is not worth substituting). */
    public function isDocumentable(): bool
    {
        return $this->payload !== null || $this->status !== null || $this->contentType !== null;
    }

    /**
     * With the pass-through status bound to a concrete literal recovered at the call site — used when a
     * caller folds the argument the callee forwards to its status. Clears {@see $statusParam} so the
     * bound shape reads as a resolved literal.
     */
    public function withBoundStatus(LiteralT $status): self
    {
        return new self($this->payload, $status, null, $this->contentType, $this->delegates);
    }

    /**
     * Re-home a pass-through status onto an OUTER callee's parameter (transitive binding through a hop):
     * the inner callee forwarded its status from parameter X, and the outer call passed its own
     * parameter Y into X, so from the outer callee's vantage the status passes through Y.
     */
    public function withStatusParam(?string $statusParam): self
    {
        return new self($this->payload, null, $statusParam, $this->contentType, $this->delegates);
    }

    /**
     * The recovered shape as a `JsonResponse<payload, status, contentType>` {@see ClassT}, or null when
     * nothing documentable was recovered. An unfolded status/payload is emitted as an {@see UnknownT}
     * placeholder so the pipeline can fall back honestly (the exception's own status hint) rather than
     * silently defaulting; the content-type arg is present only when explicitly recovered.
     */
    public function toClassT(string $fqcn): ?ClassT
    {
        if (! $this->isDocumentable()) {
            return null;
        }

        $args = [
            $this->payload ?? new UnknownT('payload not folded'),
            $this->status ?? new UnknownT('status not folded'),
        ];
        if ($this->contentType !== null) {
            $args[] = new LiteralT($this->contentType);
        }

        return new ClassT($fqcn, $args);
    }
}

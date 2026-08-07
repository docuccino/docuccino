<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

/**
 * The value-flow provenance of one recovered value: the callee PARAMETER it reads from, and HOW it
 * reads it ({@see AccessorKind} — the parameter itself, or `->value`/`->name`/`->method()` on it when the
 * parameter is an enum). It lets a call site fold the value once it knows the concrete argument bound to
 * that parameter (a literal, a caller parameter to re-home one hop out, or — the enum-case hop — a
 * concrete case whose accessor folds via {@see EnumAccessorFolder}).
 *
 * Transient: consumed by binding, never serialised (it round-trips no further than the per-callee memo).
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final readonly class ParamAccessor
{
    public function __construct(
        public string $param,
        public AccessorKind $kind = AccessorKind::Identity,
        public ?string $method = null,
    ) {}

    /** A plain pass-through of the parameter (the value IS the parameter, no accessor). */
    public static function identity(string $param): self
    {
        return new self($param, AccessorKind::Identity);
    }

    /** Re-home this accessor onto an OUTER parameter (transitive binding through a call hop). */
    public function withParam(string $param): self
    {
        return new self($param, $this->kind, $this->method);
    }

    /** Whether two accessors read the SAME value off the same parameter (status-marker matching). */
    public function equals(self $other): bool
    {
        return $this->param === $other->param
            && $this->kind === $other->kind
            && $this->method === $other->method;
    }
}

<?php

declare(strict_types=1);

namespace Docuccino\SpikeB;

/**
 * Prototype of the plan's `ConstValue` — the return of `TypeScope::constantValueOf()`.
 *
 * A recovered constant is one of:
 *   - a scalar literal (string / int / bool / null),
 *   - a *call descriptor* for a factory static-call we deliberately do NOT
 *     collapse to its runtime type (e.g. `AllowedFilter::exact('status')`),
 *   - an array of the above,
 *   - unknown (with a reason), when folding failed.
 *
 * The descriptor case is the crux: PHPStan would happily tell us the *type* of
 * `AllowedFilter::exact('status')` is `AllowedFilter`, but the documentation
 * needs the *call* — factory name + folded args — so the QB integration can turn
 * it into a `filter[status]` parameter. So constantValueOf must special-case
 * factory calls at the AST level BEFORE deferring to PHPStan's type folding.
 */
final class ConstValue
{
    public const KIND_SCALAR = 'scalar';

    public const KIND_DESCRIPTOR = 'descriptor';

    public const KIND_ARRAY = 'array';

    public const KIND_UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $kind,
        public readonly mixed $scalar = null,
        public readonly ?string $factory = null,
        /** @var list<ConstValue> */
        public readonly array $args = [],
        /** @var list<ConstValue> */
        public readonly array $items = [],
        public readonly ?string $reason = null,
    ) {}

    public static function scalar(mixed $value): self
    {
        return new self(self::KIND_SCALAR, scalar: $value);
    }

    /** @param list<ConstValue> $args */
    public static function descriptor(string $factory, array $args): self
    {
        return new self(self::KIND_DESCRIPTOR, factory: $factory, args: $args);
    }

    /** @param list<ConstValue> $items */
    public static function array(array $items): self
    {
        return new self(self::KIND_ARRAY, items: $items);
    }

    public static function unknown(string $reason): self
    {
        return new self(self::KIND_UNKNOWN, reason: $reason);
    }

    public function isScalar(): bool
    {
        return $this->kind === self::KIND_SCALAR;
    }

    /** Canonical, deterministic string form for the report. */
    public function render(): string
    {
        return match ($this->kind) {
            self::KIND_SCALAR => $this->renderScalar($this->scalar),
            self::KIND_DESCRIPTOR => sprintf(
                '%s(%s)',
                $this->factory,
                implode(', ', array_map(static fn (ConstValue $a) => $a->render(), $this->args)),
            ),
            self::KIND_ARRAY => sprintf(
                '[%s]',
                implode(', ', array_map(static fn (ConstValue $i) => $i->render(), $this->items)),
            ),
            default => sprintf('<unknown: %s>', $this->reason ?? '?'),
        };
    }

    private function renderScalar(mixed $value): string
    {
        return match (true) {
            is_string($value) => "'{$value}'",
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => (string) $value,
        };
    }
}

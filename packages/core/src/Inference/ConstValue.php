<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use Docuccino\Core\Support\Fqcn;

/**
 * A constant value recovered by {@see TypeScope::constantValueOf()}. Closed set:
 *
 *   - `scalar`     — a string / int / float / bool / null literal;
 *   - `descriptor` — a factory static-call we deliberately do NOT collapse to
 *     its runtime type (`AllowedFilter::exact('status')` → factory + folded
 *     args). The `factory` records the FULLY-QUALIFIED `Class::method`
 *     (`Spatie\QueryBuilder\AllowedFilter::exact`); {@see render()} shortens the
 *     class for display. This variant is the crux of the Scramble-Pro-beater (Spike B):
 *     PHPStan would tell us the *type* is `AllowedFilter`, but the docs need the
 *     *call*, so factory calls are folded at the AST level before PHPStan type
 *     collapse;
 *   - `array`      — an array of the above (per-item recursion);
 *   - `unknown`    — folding failed, with a reason.
 */
final readonly class ConstValue
{
    public const KIND_SCALAR = 'scalar';

    public const KIND_DESCRIPTOR = 'descriptor';

    public const KIND_ARRAY = 'array';

    public const KIND_UNKNOWN = 'unknown';

    /**
     * @param  list<ConstValue>  $args
     * @param  list<ConstValue>  $items
     */
    private function __construct(
        public string $kind,
        public string|int|float|bool|null $scalar = null,
        public ?string $factory = null,
        public array $args = [],
        public array $items = [],
        public ?string $reason = null,
    ) {}

    public static function scalar(string|int|float|bool|null $value): self
    {
        return new self(self::KIND_SCALAR, scalar: $value);
    }

    /**
     * @param  list<ConstValue>  $args
     */
    public static function descriptor(string $factory, array $args): self
    {
        return new self(self::KIND_DESCRIPTOR, factory: $factory, args: $args);
    }

    /**
     * @param  list<ConstValue>  $items
     */
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

    public function isDescriptor(): bool
    {
        return $this->kind === self::KIND_DESCRIPTOR;
    }

    public function isArray(): bool
    {
        return $this->kind === self::KIND_ARRAY;
    }

    /** Canonical, deterministic string form (used in reports and tests). */
    public function render(): string
    {
        return match ($this->kind) {
            self::KIND_SCALAR => $this->renderScalar($this->scalar),
            self::KIND_DESCRIPTOR => sprintf(
                '%s(%s)',
                self::shortFactory((string) $this->factory),
                implode(', ', array_map(static fn (ConstValue $a): string => $a->render(), $this->args)),
            ),
            self::KIND_ARRAY => sprintf(
                '[%s]',
                implode(', ', array_map(static fn (ConstValue $i): string => $i->render(), $this->items)),
            ),
            default => sprintf('<unknown: %s>', $this->reason ?? '?'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->kind) {
            self::KIND_SCALAR => ['kind' => self::KIND_SCALAR, 'scalar' => $this->scalar],
            self::KIND_DESCRIPTOR => [
                'kind' => self::KIND_DESCRIPTOR,
                'factory' => $this->factory,
                'args' => array_map(static fn (ConstValue $a): array => $a->toArray(), $this->args),
            ],
            self::KIND_ARRAY => [
                'kind' => self::KIND_ARRAY,
                'items' => array_map(static fn (ConstValue $i): array => $i->toArray(), $this->items),
            ],
            default => ['kind' => self::KIND_UNKNOWN, 'reason' => $this->reason],
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $kind = $data['kind'] ?? self::KIND_UNKNOWN;

        return match ($kind) {
            self::KIND_SCALAR => self::scalar(self::scalarFrom($data['scalar'] ?? null)),
            self::KIND_DESCRIPTOR => self::descriptor(
                is_string($data['factory'] ?? null) ? $data['factory'] : '',
                self::listFrom($data['args'] ?? []),
            ),
            self::KIND_ARRAY => self::array(self::listFrom($data['items'] ?? [])),
            default => self::unknown(is_string($data['reason'] ?? null) ? $data['reason'] : '?'),
        };
    }

    private static function scalarFrom(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    /**
     * @return list<ConstValue>
     */
    private static function listFrom(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $i): self => is_array($i) ? self::fromArray($i) : self::unknown('malformed const value'),
            $items,
        ));
    }

    /**
     * Shorten a fully-qualified `Class::method` factory to `ShortClass::method`
     * for display, leaving the stored `factory` (an FQCN) untouched.
     */
    private static function shortFactory(string $factory): string
    {
        $sep = strpos($factory, '::');
        $class = $sep === false ? $factory : substr($factory, 0, $sep);
        $method = $sep === false ? null : substr($factory, $sep + 2);

        $shortClass = Fqcn::short($class);

        return $method === null ? $shortClass : $shortClass.'::'.$method;
    }

    private function renderScalar(string|int|float|bool|null $value): string
    {
        return match (true) {
            is_string($value) => "'{$value}'",
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => (string) $value,
        };
    }
}

<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * A union of two or more member types. Construction is canonical: nested unions
 * are flattened, duplicate members collapsed, and the survivors sorted by
 * {@see DType::canonicalKey()} — so `A|B` and `B|A` serialize identically and
 * nullability always renders with `NullT` last.
 *
 * Use {@see UnionT::of()} to build one; it collapses a single survivor back to
 * that member (a union is never of arity < 2).
 */
final readonly class UnionT extends DType
{
    public const KIND = 'union';

    /**
     * @param  list<DType>  $members  already flattened, deduped and sorted
     */
    public function __construct(public array $members) {}

    /**
     * Canonicalising factory. Returns the sole member when the union collapses
     * to arity 1, or `UnknownT` when there are no members.
     *
     * @param  list<DType>  $members
     */
    public static function of(array $members): DType
    {
        $unique = self::canonicalMembers($members, self::class);

        return match (count($unique)) {
            0 => new UnknownT('empty union'),
            1 => $unique[0],
            default => new self($unique),
        };
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'members' => array_map(static fn (DType $t): array => $t->toArray(), $this->members),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): DType
    {
        $members = $data['members'] ?? [];

        return self::of(
            is_array($members)
                ? array_values(array_map(
                    static fn (mixed $t): DType => is_array($t) ? DType::fromArray($t) : new UnknownT('malformed union member'),
                    $members,
                ))
                : [],
        );
    }
}

<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Document\Operation;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Support\Arr;

/**
 * Every operation a path item states — its fixed methods, then OpenAPI 3.2's `additionalOperations` — read
 * here by every walk the differ makes over operations, so none of them sees fewer than the comparison does.
 *
 * A custom method is sent as its key is written, case and all: a custom `get` is another method than the
 * fixed `get` (`GET`), and a `GET` key, which OpenAPI forbids, sends the fixed one's request line from
 * somewhere else. So each is told apart by where it is WRITTEN — the fixed method's name uppercased, or
 * `additionalOperations/` and the key escaped as the node index's pointer escapes it — which no two share.
 *
 * @internal
 */
final class PathOperations
{
    private const string CUSTOM = 'additionalOperations';

    /**
     * @return list<array{0: string, 1: string, 2: Operation}> the method a client sends, where the operation is written, and the operation
     */
    public static function of(PathItem $item): array
    {
        $out = [];

        foreach ($item->operations as $method => $operation) {
            $out[] = [strtoupper($method), strtoupper($method), $operation];
        }

        foreach (self::customIn($item->rest) as [$method, $operation]) {
            $out[] = [$method, self::CUSTOM.'/'.Pointer::escape($method), Operation::fromArray(Arr::stringKeyed($operation))];
        }

        return $out;
    }

    /**
     * $item as written, with $change applied to every operation it states, fixed or custom.
     *
     * @param  array<array-key, mixed>  $item
     * @param  callable(array<array-key, mixed>): array<array-key, mixed>  $change
     * @return array<array-key, mixed>
     */
    public static function rewritten(array $item, callable $change): array
    {
        foreach (PathItem::METHODS as $method) {
            if (is_array($item[$method] ?? null)) {
                $item[$method] = $change($item[$method]);
            }
        }

        $custom = self::customIn($item);
        if ($custom !== []) {
            /** @var array<array-key, mixed> $written */
            $written = $item[self::CUSTOM];
            foreach ($custom as [$method, $operation]) {
                $written[$method] = $change($operation);
            }
            $item[self::CUSTOM] = $written;
        }

        return $item;
    }

    /**
     * The custom operations among $members, each with its method — a pair rather than a key, since PHP
     * hands a method spelt in digits back as an int. A member that is not an object states no operation,
     * as a fixed method's would not.
     *
     * @param  array<array-key, mixed>  $members
     * @return list<array{string, array<array-key, mixed>}>
     */
    private static function customIn(array $members): array
    {
        $custom = $members[self::CUSTOM] ?? null;

        $out = [];
        foreach (is_array($custom) ? $custom : [] as $method => $operation) {
            if (is_array($operation)) {
                $out[] = [(string) $method, $operation];
            }
        }

        return $out;
    }
}

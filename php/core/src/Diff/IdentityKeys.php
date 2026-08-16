<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * Keys the two sides of a diff by the identity each node carries, falling back to a structural key where
 * there is no identity to key on.
 *
 * An id is read off an artifact nobody validated, so nothing stops two nodes claiming the same one. Keyed
 * naively the second overwrites the first and the node it hid disappears from the comparison — a removal
 * reported as no change, which is the one answer a diff must never give. So an id claimed more than once
 * on either side is qualified with the node's structural key on BOTH sides: the nodes that do correspond
 * still meet, and the extra one reads as the add or the remove it is. An id claimed once is its own key,
 * exactly as before.
 *
 * @internal
 */
final class IdentityKeys
{
    /**
     * @template T
     *
     * @param  list<array{0: ?string, 1: string, 2: T}>  $old  identity (null when not pairing by identity), structural key, node
     * @param  list<array{0: ?string, 1: string, 2: T}>  $new
     * @return array{array<string, T>, array<string, T>}
     */
    public static function pair(array $old, array $new): array
    {
        $ambiguous = self::claimedTwice($old) + self::claimedTwice($new);

        return [self::keyed($old, $ambiguous), self::keyed($new, $ambiguous)];
    }

    /**
     * @param  list<array{0: ?string, 1: string, 2: mixed}>  $entries
     * @return array<string, true>
     */
    private static function claimedTwice(array $entries): array
    {
        $seen = [];
        $twice = [];

        foreach ($entries as [$id]) {
            if ($id === null) {
                continue;
            }

            if (isset($seen[$id])) {
                $twice[$id] = true;
            }

            $seen[$id] = true;
        }

        return $twice;
    }

    /**
     * @template T
     *
     * @param  list<array{0: ?string, 1: string, 2: T}>  $entries
     * @param  array<string, true>  $ambiguous
     * @return array<string, T>
     */
    private static function keyed(array $entries, array $ambiguous): array
    {
        $out = [];

        foreach ($entries as [$id, $structural, $node]) {
            $key = match (true) {
                $id === null => $structural,
                isset($ambiguous[$id]) => $id.'#'.$structural,
                default => $id,
            };

            $out[$key] = $node;
        }

        return $out;
    }
}

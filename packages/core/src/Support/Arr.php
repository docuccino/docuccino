<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * Small array helpers for the JSON boundary, where decoded data is typed
 * `array<mixed, mixed>` but object members are always string-keyed.
 */
final class Arr
{
    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $value): array
    {
        $out = [];

        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}

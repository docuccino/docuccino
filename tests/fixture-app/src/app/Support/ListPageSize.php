<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The shared page-size clamp every list endpoint runs its `per_page` through: default 15, pinned into
 * `[1, $max]` rather than rejected. The request is an ARGUMENT here — nothing at the paginating call site
 * names the key, so only this body says the endpoint reads one.
 */
final class ListPageSize
{
    public static function clamp(Request $request, int $default = 15, int $max = 100): int
    {
        return max(1, min($request->integer('per_page', $default), $max));
    }

    /**
     * The same clamp with the read NAMED first, which is the other half of how apps write it — and a
     * different key, since nothing here matches on `per_page`.
     */
    public static function limit(Request $request, int $max = 100): int
    {
        $limit = $request->integer('limit', 15);

        return min($limit, $max);
    }
}

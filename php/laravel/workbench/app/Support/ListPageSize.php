<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Illuminate\Http\Request;

/**
 * The shared page-size clamp a list endpoint runs its `per_page` through, taking the request as an
 * ARGUMENT — the shape `RequestPageSizeReader` has to follow a paginator's size argument into. Parsed as
 * test INPUT: its real source lines are what the reader's reflection correlation is proven against, so
 * moving `clamp()` within this file is fine but its body is data.
 */
final class ListPageSize
{
    public static function clamp(Request $request, int $default = 15, int $max = 100): int
    {
        return max(1, min($request->integer('per_page', $default), $max));
    }

    /** The negative twin: a size helper that reads nothing off the request, so it names no key. */
    public static function fixed(Request $request, int $default = 15): int
    {
        return max(1, $default);
    }

    /** Two keys in one body: which of them is the size is not decidable, so neither is claimed. */
    public static function ambiguous(Request $request): int
    {
        return max($request->integer('per_page', 15), $request->integer('limit', 20));
    }

    /** One key, two fallbacks: the key still holds, but no default can depend on which read came first. */
    public static function repeated(Request $request): int
    {
        return max($request->integer('per_page', 15), $request->integer('per_page', 20));
    }
}

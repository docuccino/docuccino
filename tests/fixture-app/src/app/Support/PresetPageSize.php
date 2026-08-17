<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Two helpers that READ the request and answer a size the request never gave them: `preset` names one of
 * three fixed sizes, and `sort` decides how many recent rows are worth showing. Neither key is a page size
 * — the value the caller pages by is a literal in both — so neither belongs in the document as one.
 */
final class PresetPageSize
{
    /** The key picks an arm; the size is whichever literal that arm holds. */
    public static function forPreset(Request $request): int
    {
        return match ($request->input('preset')) {
            'small' => 10,
            'large' => 100,
            default => 25,
        };
    }

    /** The key decides the ordering, and a recent-first list is deliberately shorter. */
    public static function whenRecent(Request $request): int
    {
        if ($request->input('sort') === 'recent') {
            return 10;
        }

        return 25;
    }
}

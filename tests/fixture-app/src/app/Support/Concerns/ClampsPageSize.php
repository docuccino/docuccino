<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Http\Request;

/**
 * The shared page-size clamp as a TRAIT, which is where an application that pages several resources the
 * same way puts it. PHP reports the method as the using class's own while reflection reports the trait's
 * file, so recovering the key from here needs the read's file and its line to come from one source.
 *
 * The read below is deliberately at the same LINE as a method of the using class: a line number is only
 * ever meaningful together with the file it came from.
 */
trait ClampsPageSize
{
    public static function pageSize(Request $request, int $max = 100): int
    {
        return max(1, min($request->integer('per_page', 15), $max));
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Concerns\ClampsPageSize;

/**
 * The team list's two sizes: the shared clamp arrives from a trait, and the summary endpoint's own size is
 * fixed. Both are static helpers on one class, which is how PHP reports a trait-imported method — so the
 * trait's read and this file's own methods sit at overlapping LINE numbers in two different files, and
 * only the file a line came from can say which method wrote it.
 */
final class TeamPageSize
{
    use ClampsPageSize;

    /** The summary pages twenty at a time. The request has no say in it. */
    public static function summarySize(): int
    {
        return 20;
    }
}

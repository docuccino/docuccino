<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * An int-backed enum a rule names by case, so its cases publish as the integers Laravel compares.
 */
enum ListingPriority: int
{
    case Low = 1;

    case Normal = 2;

    case High = 3;

    /** An alias for one case, written as a constant rather than a case of its own. */
    public const DEFAULT = self::Normal;

    /** A plain constant on an enum, which is a value and not a case. */
    public const LABEL = 'priority';
}

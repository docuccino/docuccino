<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A pure enum a rule names by case: it has no backing value, so Laravel compares its case names.
 */
enum ListingVisibility
{
    case Public;

    case Unlisted;
}

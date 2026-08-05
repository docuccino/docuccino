<?php

declare(strict_types=1);

namespace App\Enums;

use Docuccino\Attributes\CaseDescription;

/**
 * A backed enum an Eloquent model casts a column to, so the real-engine Query Builder proof recovers
 * its backing values (schema `enum`) and `#[CaseDescription]` prose (`x-enumDescriptions`) for an
 * `AllowedFilter::exact` on that column — end-to-end through the real PHPStan/Larastan engine.
 */
enum ListingStatus: string
{
    #[CaseDescription('Visible to the public and accepting applications.')]
    case Open = 'open';

    #[CaseDescription('No longer accepting applications.')]
    case Closed = 'closed';

    case Draft = 'draft';
}

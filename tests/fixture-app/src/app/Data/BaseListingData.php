<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\Concerns\HasRevision;
use App\Enums\ListingStatus;
use Spatie\LaravelData\Data;

/**
 * The shared half of every listing payload, in its own class the way an app factors common fields out.
 * Its properties are inherited by {@see ListingSummaryData}, and `$status` is a backed enum whose CASES
 * end up copied into the subclass's recovered metadata.
 */
abstract class BaseListingData extends Data
{
    use HasRevision;

    public function __construct(
        public int $id,
        public ListingStatus $status,
    ) {}
}

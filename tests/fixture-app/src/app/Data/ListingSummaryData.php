<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ListingStatus;

/**
 * A listing payload that declares one property of its own and inherits the rest. Everything the engine
 * reports about it beyond `$title` is written in another file.
 */
final class ListingSummaryData extends BaseListingData
{
    public function __construct(
        int $id,
        ListingStatus $status,
        public string $title,
    ) {
        parent::__construct($id, $status);
    }
}

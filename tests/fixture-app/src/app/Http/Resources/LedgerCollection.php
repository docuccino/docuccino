<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A named collection of {@see LedgerResource}, which that resource's `::collection()` builds.
 */
class LedgerCollection extends ResourceCollection
{
    public $collects = LedgerResource::class;
}

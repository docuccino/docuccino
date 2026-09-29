<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A named collection of {@see JournalResource}, which that resource's untyped `newCollection()` builds.
 */
class JournalCollection extends ResourceCollection
{
    public $collects = JournalResource::class;
}

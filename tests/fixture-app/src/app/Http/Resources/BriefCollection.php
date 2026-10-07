<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A collection branching inline on the length-aware paginator contract alone: a simple or cursor page
 * takes the plain list's arm.
 */
class BriefCollection extends AnonymousResourceCollection
{
    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        return $this->resource instanceof LengthAwarePaginator
            ? ['paged' => true]
            : ['meta' => ['brief' => true], 'paged' => false];
    }
}

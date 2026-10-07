<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;

/**
 * A collection choosing its members with a `match (true)` on the paginator classes Laravel dispatches on.
 */
class BulletinCollection extends AnonymousResourceCollection
{
    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        return match (true) {
            $this->resource instanceof AbstractPaginator, $this->resource instanceof AbstractCursorPaginator => ['paged' => true],
            default => ['meta' => ['bulletin' => true]],
        };
    }
}

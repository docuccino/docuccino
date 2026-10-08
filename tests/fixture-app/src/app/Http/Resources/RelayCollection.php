<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;

/**
 * A collection whose `with()` swaps a page for the items on it before answering, so what `$this->resource`
 * is at a return after the swap says nothing about what the collection was handed.
 */
class RelayCollection extends AnonymousResourceCollection
{
    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        if ($this->resource instanceof AbstractPaginator) {
            $this->resource = $this->resource->getCollection();
        }

        if ($this->resource instanceof Collection) {
            return ['meta' => (object) []];
        }

        return [];
    }
}

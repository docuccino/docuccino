<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * A chain whose soft-delete filter is published under a key the deployment chooses. Spatie documents
 * `AllowedFilter::trashed()` as filtering on `trashed`, and this call passes a name — so the endpoint
 * accepts some other key, and `trashed` is a query parameter it does not have.
 */
class TrashedFilterController extends Controller
{
    /**
     * @return LengthAwarePaginator<int, Listing>
     */
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Listing::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::trashed($this->trashedFilterKey()),
            ])
            ->paginate(20);
    }

    private function trashedFilterKey(): string
    {
        return (string) config('listings.trashed_filter', 'archived');
    }
}

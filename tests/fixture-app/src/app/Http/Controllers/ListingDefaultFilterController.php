<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListingStatus;
use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Enums\FilterOperator;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Filters naming enum cases. Spatie filters on a default as if it had been sent, and the query binds a
 * case as its backing value, so `status` defaults to `open` and the helper's filter to `closed`. An
 * operator filter's comparison is a `FilterOperator` case, and only an equality one types off its column.
 */
class ListingDefaultFilterController extends Controller
{
    /**
     * @return LengthAwarePaginator<int, Listing>
     */
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Listing::class)
            ->allowedFilters([
                AllowedFilter::exact('status')->default(ListingStatus::Open),
                $this->previousStatusFilter(),
                AllowedFilter::operator('active', FilterOperator::EQUAL),
                AllowedFilter::operator('title', FilterOperator::GREATER_THAN),
            ])
            ->paginate(20);
    }

    private function previousStatusFilter(ListingStatus $fallback = ListingStatus::Closed): AllowedFilter
    {
        return AllowedFilter::exact('previous_status')->default($fallback);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Real-engine Query Builder enum-cast proof. The chain filters `Listing` on its enum-cast `status`
 * column via `AllowedFilter::exact('status')` — zero doc annotations — so the tracer must recover the
 * subject model (`QueryBuilder::for(Listing::class)`) and, from the model's `$casts`, type the filter
 * as the enum's backing values.
 */
class ListingQueryController extends Controller
{
    /**
     * @return LengthAwarePaginator<int, Listing>
     */
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Listing::class)
            ->allowedFilters([
                // Full-text match on the listing title.
                'title',
                AllowedFilter::exact('status'),
            ])
            ->paginate(20);
    }
}

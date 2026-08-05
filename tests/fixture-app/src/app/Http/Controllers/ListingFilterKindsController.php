<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Real-engine proof of the round-2 filter-kind inference (scope value-parameter typing + callback
 * closure column recovery) — zero doc annotations. The tracer must recover the subject model
 * (`Listing`), type `AllowedFilter::scope('status')` off the `scopeStatus` value parameter (a backed
 * enum → the enum's values + case descriptions), and recover the `AllowedFilter::callback('active', …)`
 * closure's `where('active', …)` column → the model's boolean cast.
 */
class ListingFilterKindsController extends Controller
{
    /**
     * @return LengthAwarePaginator<int, Listing>
     */
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Listing::class)
            ->allowedFilters([
                AllowedFilter::scope('status'),
                AllowedFilter::callback('active', function (Builder $query, mixed $value): void {
                    $query->where('active', $value);
                }),
            ])
            ->paginate(20);
    }
}

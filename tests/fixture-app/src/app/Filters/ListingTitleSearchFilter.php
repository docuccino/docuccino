<?php

declare(strict_types=1);

namespace App\Filters;

use App\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A custom Query Builder filter class, registered the way the package documents it — as an INSTANCE
 * (`AllowedFilter::custom('title_search', new ListingTitleSearchFilter)`), parentheses and all optional.
 * Recovering its FQCN means typing the `new` expression at the call site, not reading a class-string.
 * Only ever analysed.
 *
 * @implements Filter<Listing>
 */
class ListingTitleSearchFilter implements Filter
{
    /**
     * @param  Builder<Listing>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->where('title', 'like', '%'.$value.'%');
    }
}

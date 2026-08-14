<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\Listing;
use App\Support\ListQueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

/**
 * A query object whose allow-list entries are each built by an INSTANCE METHOD of the query class —
 * `->allowedFilters($this->termFilter(), $this->facetFilter('status', 'status'))`. Nothing at the call
 * site names the filters, so recovering them means folding what each method returns: the zero-argument
 * `termFilter()` keeps its public name entirely inside its body, and `facetFilter()` only names one once
 * the call site's arguments are bound to its parameters. Under `modules/`, so the same fold has to work
 * through the `$query->query()` hop beyond the engine's project paths. Only ever analysed.
 */
final readonly class PositionSearchQuery
{
    /**
     * @return ListQueryBuilder<Listing>
     */
    public function query(): ListQueryBuilder
    {
        return ListQueryBuilder::for(Listing::class)
            ->allowedFilters(
                $this->termFilter(),
                $this->facetFilter('status', 'status'),
            )
            ->allowedSorts($this->titleSort())
            ->defaultSort($this->titleSort());
    }

    /** A free-text search filter whose public name and column both live in this body. */
    private function termFilter(): AllowedFilter
    {
        return AllowedFilter::callback('q', function (Builder $query, mixed $value): void {
            $query->where('title', $value);
        });
    }

    private function facetFilter(string $name, string $column): AllowedFilter
    {
        return AllowedFilter::exact($name, $column);
    }

    private function titleSort(): AllowedSort
    {
        return AllowedSort::field('title');
    }
}

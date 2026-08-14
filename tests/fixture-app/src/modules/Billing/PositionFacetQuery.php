<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\Listing;
use App\Support\ListQueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

/**
 * The other shape of the same problem: the allow-lists are spread out of methods returning ARRAYS —
 * `->allowedFilters(...$this->allowedFilters())` — so one folded return has to expand into several
 * entries, each keeping the leading comment written next to it inside the helper. Its sort is built by a
 * method that BRANCHES, which is the honest limit of the same fold. Only ever analysed.
 */
final readonly class PositionFacetQuery
{
    public function __construct(private bool $descending = false) {}

    /**
     * @return ListQueryBuilder<Listing>
     */
    public function query(): ListQueryBuilder
    {
        return ListQueryBuilder::for(Listing::class)
            ->allowedFilters(...$this->allowedFilters())
            ->allowedIncludes(...$this->allowedIncludes())
            ->allowedSorts($this->configuredSort());
    }

    /** Two arms: there is no single value to fold, so this one has to degrade to a diagnostic. */
    private function configuredSort(): AllowedSort
    {
        if ($this->descending) {
            return AllowedSort::field('-title');
        }

        return AllowedSort::field('title');
    }

    /**
     * @return list<AllowedFilter>
     */
    public function allowedFilters(): array
    {
        return [
            // Whether the position is still open.
            AllowedFilter::exact('active'),
            AllowedFilter::partial('title'),
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedIncludes(): array
    {
        return ['employer'];
    }
}

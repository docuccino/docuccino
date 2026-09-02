<?php

declare(strict_types=1);

namespace App\Queries\Concerns;

use App\Models\Product;
use App\Queries\ExportSorts;
use App\Support\ListQueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * The export list's allow-list as a shared concern, which is where an application offering the same
 * filters on more than one list puts it. PHP reports the method as the using class's own, so every
 * entry below is written in a file the query class never names — the trace harvests them out of the
 * walk of that class's file, and the hop this body makes carries on one file further.
 */
trait FiltersExports
{
    /**
     * @param  ListQueryBuilder<Product>  $query
     * @return ListQueryBuilder<Product>
     */
    protected function exportFilters(ListQueryBuilder $query): ListQueryBuilder
    {
        return ExportSorts::apply($query->allowedFilters(['sku', AllowedFilter::exact('status')]));
    }
}

<?php

declare(strict_types=1);

namespace Modules\Billing\Concerns;

use Spatie\QueryBuilder\AllowedFilter;

/**
 * The billing module's shared facets, spread into an allow-list. An ARRAY return is not a type the trace
 * follows, so nothing ever descends into this body: the return fold is its only reader, and the file the
 * entries are written in is one the walk never opens by name.
 */
trait NamesExportFacets
{
    /**
     * @return list<AllowedFilter>
     */
    private function exportFacets(): array
    {
        return [AllowedFilter::exact('facet'), AllowedFilter::partial('label')];
    }
}

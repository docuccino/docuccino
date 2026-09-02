<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\Listing;
use Modules\Billing\Concerns\NamesExportFacets;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * A modular query object outside the descend scope, whose allow-list is spread from a shared concern. So
 * the only hop out of this file the trace is entitled to make is the fold of that helper's return, and the
 * only file the facets were written in is the concern's.
 */
final class ExportFacetQuery
{
    use NamesExportFacets;

    /**
     * @return QueryBuilder<Listing>
     */
    public function query(): QueryBuilder
    {
        return QueryBuilder::for(Listing::class)->allowedFilters(...$this->exportFacets());
    }
}

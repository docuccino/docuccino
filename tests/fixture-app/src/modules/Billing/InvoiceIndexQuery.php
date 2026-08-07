<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\Listing;
use App\Support\ListQueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * The generic query-object pattern (`#[ListQuery] → Queries\* → ListQueryBuilder::for()`, as a large
 * production Laravel app writes it): the
 * whole `allowedFilters()`/`allowedSorts()` chain lives inside `query()`, reached from the controller
 * via `$query->query()->…`. This class lives OUTSIDE the engine's configured project paths (under
 * `modules/`, not `app/`) — exactly the modular layout that hides a real app's filters — so recovering the
 * allow-lists here proves the engine follows the `query(): ListQueryBuilder` return-type hop beyond
 * the project paths (never into vendor). Only ever analysed.
 */
final readonly class InvoiceIndexQuery
{
    /**
     * @return ListQueryBuilder<Listing>
     */
    public function query(): ListQueryBuilder
    {
        return ListQueryBuilder::for(Listing::class)
            ->allowedFilters([
                // The listing's publication status.
                AllowedFilter::exact('status'),
                AllowedFilter::partial('title'),
            ])
            ->allowedSorts(['title'])
            ->defaultSort('title');
    }
}

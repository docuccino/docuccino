<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Support\ListQueryBuilder;

/**
 * A query object whose allow-list is built from a user-land filter FACTORY ({@see InvoiceFilters})
 * rather than direct `AllowedFilter::*` calls — the `ListFilters` idiom. Recovering the enum/boolean
 * typing here proves the QB trace types a project-factory filter from its call-site arguments (the
 * backed-enum class-string, the key→cast column) through the `$query->query()` hop, without descending
 * into the factory body — and the zero-argument alias proves the other half, where the name is written
 * only as a parameter default and the factory's return has to be folded. Only ever analysed.
 */
final readonly class OrderIndexQuery
{
    /**
     * @return ListQueryBuilder<Listing>
     */
    public function query(): ListQueryBuilder
    {
        return ListQueryBuilder::for(Listing::class)
            ->allowedFilters([
                InvoiceFilters::enum('status', ListingStatus::class),
                InvoiceFilters::boolean('active'),
                InvoiceFilters::state(),
            ])
            ->defaultSort('title');
    }
}

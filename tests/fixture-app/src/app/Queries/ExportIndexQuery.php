<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Product;
use App\Queries\Concerns\FiltersExports;
use App\Support\ListQueryBuilder;

/**
 * The export list's query object: the chain's origin and its default sort are written here, and the
 * allow-lists arrive from a trait. So one call graph spans two files that PHP reports as one class.
 */
final readonly class ExportIndexQuery
{
    use FiltersExports;

    /**
     * @return ListQueryBuilder<Product>
     */
    public function query(): ListQueryBuilder
    {
        return $this->exportFilters(ListQueryBuilder::for(Product::class))->defaultSort('sku');
    }
}

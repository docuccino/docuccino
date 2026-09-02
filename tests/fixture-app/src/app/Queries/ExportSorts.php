<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Product;
use App\Support\ListQueryBuilder;

/**
 * The sortable columns every export list shares, applied one hop past the filters concern — so this is
 * the deepest fact the export chain publishes, and the last file its trace has to reach.
 */
final class ExportSorts
{
    /**
     * @param  ListQueryBuilder<Product>  $query
     * @return ListQueryBuilder<Product>
     */
    public static function apply(ListQueryBuilder $query): ListQueryBuilder
    {
        return $query->allowedSorts(['sku', 'created_at']);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Product;
use App\Queries\ExportIndexQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The export list action itself, shared as a controller concern — which is where an application serving
 * the same list under more than one route puts it. So even the trace's ROOT has a body written somewhere
 * other than the file the route resolved to.
 */
trait ListsExports
{
    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function listExports(): LengthAwarePaginator
    {
        return (new ExportIndexQuery)->query()->paginateList(25);
    }
}

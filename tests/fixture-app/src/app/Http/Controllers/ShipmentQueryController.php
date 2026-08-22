<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Shipment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Real-engine foreign-key hop proof. `depot_id` has no cast and is not `Shipment`'s key, so typing
 * the filter takes the whole chain: subject-model recovery, the `depot()` relation's literal
 * `belongsTo` read, and the related model's `HasUuids` key — zero doc annotations anywhere.
 */
class ShipmentQueryController extends Controller
{
    /**
     * @return LengthAwarePaginator<int, Shipment>
     */
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Shipment::class)
            ->allowedFilters([
                AllowedFilter::exact('depot_id'),
            ])
            ->paginate(20);
    }
}

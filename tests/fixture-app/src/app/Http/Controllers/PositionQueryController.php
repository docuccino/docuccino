<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Filters\UuidFilter;
use App\Models\Shipment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Real-engine shared-filter proof: the allow-list entry is built by `UuidFilter::allowed(...)`, whose
 * body the engine folds to the `AllowedFilter::custom` it wraps — naming the filter class the entry's
 * `#[QueryParameter]` lives on. Zero annotations at the call site.
 */
class PositionQueryController extends Controller
{
    /**
     * @return LengthAwarePaginator<int, Shipment>
     */
    public function index(): LengthAwarePaginator
    {
        return QueryBuilder::for(Shipment::class)
            ->allowedFilters([
                UuidFilter::allowed('position_id'),
            ])
            ->paginate(20);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The trace entries for the two method-built allow-list shapes: each action is just
 * `$query->query()->paginateList(…)`, so every filter name lives at least two hops away. Only ever
 * analysed.
 */
final readonly class PositionController
{
    public function __construct(
        private PositionSearchQuery $search,
        private PositionFacetQuery $facets,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Listing>
     */
    public function index(): LengthAwarePaginator
    {
        return $this->search->query()->paginateList(15);
    }

    /**
     * @return LengthAwarePaginator<int, Listing>
     */
    public function facets(): LengthAwarePaginator
    {
        return $this->facets->query()->paginateList(15);
    }
}

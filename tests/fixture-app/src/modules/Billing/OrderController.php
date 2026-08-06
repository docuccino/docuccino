<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The trace entry for the factory-filter proof: an injected query object whose allow-list uses the
 * {@see InvoiceFilters} factory. Only ever analysed.
 */
final readonly class OrderController
{
    public function __construct(private OrderIndexQuery $query) {}

    /**
     * @return LengthAwarePaginator<int, Listing>
     */
    public function index(): LengthAwarePaginator
    {
        return $this->query->query()->paginateList(15);
    }
}

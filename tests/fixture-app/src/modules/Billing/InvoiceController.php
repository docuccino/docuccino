<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A controller with a constructor-INJECTED query object (the `index(FormIndexQuery $query)` shape a
 * large production Laravel app uses):
 * the action body is just `$query->query()->paginateList(...)`, so the terminal is visible here but
 * the filter/sort allow-lists live one hop away inside {@see InvoiceIndexQuery::query()} — under
 * `modules/`, outside the engine's project paths. The trace entry; only ever analysed.
 */
final readonly class InvoiceController
{
    public function __construct(private InvoiceIndexQuery $query) {}

    /**
     * @return LengthAwarePaginator<int, Listing>
     */
    public function index(): LengthAwarePaginator
    {
        return $this->query->query()->paginateList(20);
    }
}

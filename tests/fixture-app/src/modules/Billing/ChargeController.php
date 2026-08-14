<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The action is HANDED its builder: the container resolves {@see ChargeListQuery} (which configures every
 * allow-list in its own constructor) and the body is nothing but the terminal. Only ever analysed.
 */
final class ChargeController
{
    /**
     * @return LengthAwarePaginator<int, Listing>
     */
    public function index(ChargeListQuery $query): LengthAwarePaginator
    {
        return $query->paginateList(25);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Queries\UserIndexQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Spike B target. From this action:
 *   - the allowedFilters/Sorts literals live TWO calls deep
 *     (listUsers → UserIndexQuery::query → the QB chain), and
 *   - pagination is behind a CUSTOM terminal one hop away
 *     (listUsers → ListQueryBuilder::paginateList → vendor paginate).
 *
 * Zero doc annotations here on purpose — the tracer must recover everything.
 */
class UserListController extends Controller
{
    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function listUsers(): LengthAwarePaginator
    {
        return (new UserIndexQuery())->query()->paginateList(25);
    }
}

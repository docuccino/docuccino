<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * A user-land QueryBuilder wrapper — the common application-side list-builder shape. `paginateList()`
 * is a CUSTOM terminal that defers to the vendor `paginate()` one hop down, so recovering the
 * pagination parameters here means reaching the vendor terminal through the call graph rather than
 * matching a literal receiver method.
 *
 * @template TModel of Model
 *
 * @extends QueryBuilder<TModel>
 */
final class ListQueryBuilder extends QueryBuilder
{
    /**
     * Custom pagination terminal. Behind this one hop sits the vendor terminal.
     *
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginateList(int $perPage = 15): LengthAwarePaginator
    {
        return $this->paginate($perPage);
    }
}

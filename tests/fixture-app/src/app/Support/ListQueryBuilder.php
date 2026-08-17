<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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

    /**
     * The same page, sized by the REQUEST instead of the call site: the clamp helper takes the request as
     * an argument and reads `per_page` off it, so the key belongs to every endpoint ending here even
     * though no call site writes it.
     *
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginateRequested(Request $request, int $default = 15, int $max = 100): LengthAwarePaginator
    {
        $perPage = ListPageSize::clamp($request, $default, $max);

        return $this->paginate($perPage);
    }
}

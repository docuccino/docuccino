<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * A user-land QueryBuilder wrapper — mirrors Eos's `ListQueryBuilder`.
 *
 * The point for Spike B: `paginateList()` is a CUSTOM terminal that internally
 * defers to the vendor `paginate()`. Scramble Pro's pagination extension only
 * recognises the literal vendor terminals on the QueryBuilder receiver, so a
 * custom terminal one hop away goes undetected. Docuccino must reach `paginate`
 * through the call graph.
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

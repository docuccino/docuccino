<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use App\Support\ListQueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * The allowed-filters/sorts chain is built INSIDE this query class, not in the
 * controller — exactly the Eos pattern that defeats Scramble Pro. From the
 * controller action the literals are two calls deep (action → query() → chain).
 */
final readonly class UserIndexQuery
{
    /**
     * @return ListQueryBuilder<User>
     */
    public function query(): ListQueryBuilder
    {
        return ListQueryBuilder::for(User::class)
            ->allowedFilters([
                'name',
                AllowedFilter::exact('status'),
                AllowedFilter::partial('email'),
            ])
            ->allowedSorts(['name', 'created_at'])
            ->defaultSort('name');
    }
}

<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Enums\ListingStatus;
use App\Filters\ListingTitleSearchFilter;
use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The shape where the query object IS the builder and configures itself in its own CONSTRUCTOR: the
 * container hands it to the action, which writes nothing but the terminal (`$query->paginateList(25)`).
 * Nothing in the action body leads to the configuration — a `new` is not a call the trace descends into —
 * so the allow-lists are only recoverable by tracing this constructor as a root of its own.
 *
 * The entries are deliberately mixed: a project factory carrying a backed-enum class-string, another
 * typing off its key column, a first-class-callable callback (whose column stays out of reach, so it is
 * documented as a plain string rather than guessed at), a parenless `new` custom filter, an entry only its
 * own method body names, and one built by a BRANCHING method — the fold's honest limit. Only ever analysed.
 *
 * @extends QueryBuilder<Listing>
 */
final class ChargeListQuery extends QueryBuilder
{
    public function __construct()
    {
        parent::__construct(Listing::query()->with(['employer']));

        $this->allowedFilters(
            InvoiceFilters::enum('status', ListingStatus::class),
            InvoiceFilters::boolean('active'),
            AllowedFilter::callback('tag', $this->tagFilter(...)),
            AllowedFilter::custom('title_search', new ListingTitleSearchFilter),
            $this->stateFilter(),
            $this->configuredFilter(),
        )
            ->allowedSorts('title', 'created_at')
            ->allowedIncludes('employer')
            ->defaultSort('title');
    }

    /**
     * The custom paginating terminal, one hop above the vendor one.
     *
     * @return LengthAwarePaginator<int, Listing>
     */
    public function paginateList(int $perPage = 15): LengthAwarePaginator
    {
        return $this->paginate($perPage);
    }

    /** Only this body names the filter, and only it says which column the value compares against. */
    private function stateFilter(): AllowedFilter
    {
        return AllowedFilter::exact('state', 'status');
    }

    /** Two arms: there is no single value to fold, so this one has to degrade to a diagnostic. */
    private function configuredFilter(): AllowedFilter
    {
        if (config('app.debug') === true) {
            return AllowedFilter::partial('reference');
        }

        return AllowedFilter::exact('reference');
    }

    /**
     * @param  Builder<Listing>  $query
     */
    private function tagFilter(Builder $query, mixed $value): void
    {
        $query->where('title', $value);
    }
}

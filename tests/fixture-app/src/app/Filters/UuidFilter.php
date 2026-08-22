<?php

declare(strict_types=1);

namespace App\Filters;

use App\Models\Shipment;
use Docuccino\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * A shared custom filter registered through its own static factory (`UuidFilter::allowed('key')`) —
 * the reusable-filter idiom, where a class-level `#[QueryParameter]` declares the schema ONCE for
 * every call site. Recovering it takes the engine folding the factory's return and resolving
 * `new self` to this class. Only ever analysed.
 *
 * @implements Filter<Shipment>
 */
#[QueryParameter(name: 'ignored', type: 'string', format: 'uuid', description: 'A uuid identifier.')]
class UuidFilter implements Filter
{
    /**
     * @param  Builder<Shipment>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw ValidationException::withMessages([$property => 'Must be a uuid.']);
        }

        $query->where($property, $value);
    }

    /** The factory every call site uses, wrapping the Spatie registration in one place. */
    public static function allowed(string $key, ?string $column = null): AllowedFilter
    {
        return AllowedFilter::custom($key, new self, $column ?? $key);
    }
}

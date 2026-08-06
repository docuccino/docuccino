<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Enums\ListingStatus;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * A user-land filter factory (the recurring `ListFilters`-style idiom): each static returns a Spatie
 * `AllowedFilter` built from a single unconditional `AllowedFilter::callback(...)`. The QB integration
 * recovers each filter's typing from the CALL SITE — a backed-enum class-string argument names the
 * value domain, the key is the column — without descending into these bodies. Only ever analysed.
 */
final class InvoiceFilters
{
    /**
     * @param  class-string<BackedEnum>  $enumClass
     */
    public static function enum(string $key, string $enumClass, ?string $column = null): AllowedFilter
    {
        return AllowedFilter::callback($key, static function (Builder $query, mixed $value) use ($key, $enumClass, $column): void {
            $case = is_string($value) ? $enumClass::tryFrom($value) : null;
            $query->where($column ?? $key, $case);
        });
    }

    public static function boolean(string $key, ?string $column = null): AllowedFilter
    {
        return AllowedFilter::callback($key, static function (Builder $query, mixed $value) use ($column, $key): void {
            $query->where($column ?? $key, filter_var($value, FILTER_VALIDATE_BOOLEAN));
        });
    }

    /** A convenience alias exercising that a non-enum factory still resolves through the model cast. */
    public static function status(string $key = 'status'): AllowedFilter
    {
        return self::enum($key, ListingStatus::class);
    }
}

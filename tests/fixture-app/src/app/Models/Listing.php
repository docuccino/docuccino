<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An Eloquent model the real-engine Query Builder proof filters on: its `status` column carries an
 * enum `$casts` entry, so `AllowedFilter::exact('status')` recovers the enum's backing values through
 * the real engine (subject-model recovery) + native `$casts` reflection. The `scopeStatus` local scope
 * types a `AllowedFilter::scope('status')` off its enum value parameter, and the `active` boolean cast
 * types a `AllowedFilter::callback` whose closure filters on it. Only ever reflected — never queried.
 */
class Listing extends Model
{
    public int $id;

    public string $title;

    public ListingStatus $status;

    public bool $active;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => ListingStatus::class,
        'active' => 'boolean',
    ];

    /**
     * A local scope whose value parameter is the backed enum — the scope filter types off it.
     *
     * @param  Builder<Listing>  $query
     */
    public function scopeStatus(Builder $query, ListingStatus $status): void
    {
        $query->where('status', $status);
    }
}

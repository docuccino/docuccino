<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListingStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * An Eloquent model the real-engine Query Builder proof filters on: its `status` column carries an
 * enum `$casts` entry, so `AllowedFilter::exact('status')` recovers the enum's backing values through
 * the real engine (subject-model recovery) + native `$casts` reflection. Only ever reflected — never
 * queried.
 */
class Listing extends Model
{
    public int $id;

    public string $title;

    public ListingStatus $status;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => ListingStatus::class,
    ];
}

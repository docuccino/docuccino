<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An Eloquent model the docuccino real-engine integration test reflects via classMetadata().
 * It declares its columns as typed public properties so the engine (native reflection, in-process)
 * recovers precise column types — the same classMetadata path the ModelSchema integration consumes —
 * and pairs an `is_active` boolean cast with a `bool` column so a cast-target type is proven
 * recoverable end-to-end (not only via the deterministic stub). Only ever reflected — never queried.
 */
class CatalogItem extends Model
{
    public int $id;

    public string $sku;

    public bool $is_active;

    public ?string $description;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];
}

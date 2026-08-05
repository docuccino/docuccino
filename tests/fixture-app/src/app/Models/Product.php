<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListingStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * An idiomatic Eloquent model the real-engine integration test reflects via classMetadata(): it
 * declares NO public column properties — its attributes are magic (they live in the `$attributes`
 * array) — and documents them the ide-helper way, with class-level `@property`/`@property-read`
 * docblock tags. The engine recovers those tags as the model's typed column universe, proving the
 * column source no longer depends on a shape no real model has. Its `$casts` (a backed enum + a
 * native cast + a hashed column) and `$hidden` drive the adapter-side floor + visibility union,
 * exercised in-process by the Eloquent mapper test. Only ever reflected — never queried.
 *
 * @property int $id              The product identifier.
 * @property string $sku          The stock-keeping unit.
 * @property ?string $description A nullable free-text description.
 * @property-read string $name    A read-only display name.
 */
final class Product extends Model
{
    /**
     * @var list<string>
     */
    protected $hidden = ['password'];

    /**
     * @var list<string>
     */
    protected $appends = ['full_label'];

    /**
     * @var list<string>
     */
    protected $fillable = ['sku', 'description', 'notes'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => ListingStatus::class,
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];
}

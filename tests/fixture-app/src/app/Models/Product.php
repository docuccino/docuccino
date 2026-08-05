<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Money;
use App\Enums\ListingStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An idiomatic Eloquent model the real-engine integration tests reflect via classMetadata() and
 * analyzeCallable(): it declares NO public column properties — its attributes are magic (they live in
 * the `$attributes` array) — and documents them the ide-helper way, with class-level `@property`/
 * `@property-read` docblock tags. The engine recovers those tags as the model's typed column universe.
 *
 * It also exercises the accessor + cast recovery paths: a classic `getFullLabelAttribute()` and an
 * `Attribute::make(get: …)` accessor (their return types recovered from the engine), a custom
 * `CastsAttributes` caster (`price` → its `get()` return type), the `As*` class casts (`tags`,
 * `kinds`), and a `$with` eager load (`seller`). Only ever reflected — never queried.
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
    protected $appends = ['full_label', 'display_name'];

    /**
     * @var list<string>
     */
    protected $fillable = ['sku', 'description', 'notes'];

    /**
     * @var list<string>
     */
    protected $with = ['seller'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => ListingStatus::class,
        'is_active' => 'boolean',
        'password' => 'hashed',
        'price' => Money::class,
        'tags' => AsCollection::class,
        'kinds' => AsEnumCollection::class.':'.ListingStatus::class,
    ];

    /** A classic accessor typing the `full_label` append — the engine recovers its `string` return. */
    public function getFullLabelAttribute(): string
    {
        return $this->sku.' '.$this->name;
    }

    /**
     * An `Attribute` accessor typing the `display_name` append — the engine recovers its get closure's
     * `string` return, not the method's `Attribute` return type.
     *
     * @return Attribute<string, never>
     */
    public function displayName(): Attribute
    {
        return Attribute::make(get: function (mixed $value): string {
            return (string) $this->name;
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

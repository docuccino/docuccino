<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A model fixture whose relation docblocks feed the include-value describer: one plainly documented,
 * one reached through the snake→camel lookup, one undocumented, one tag-only. Only ever reflected.
 *
 * @property string $title The almanac's display title.
 * @property string $issued_at
 */
final class Almanac extends Model
{
    /** The yearly entries, most recent first. */
    public function entries(): HasMany
    {
        return $this->hasMany(FilterCastModel::class);
    }

    /**
     * The compiler credited on the cover.
     */
    public function chiefEditor(): BelongsTo
    {
        return $this->belongsTo(FilterCastModel::class);
    }

    public function errata(): HasMany
    {
        return $this->hasMany(FilterCastModel::class);
    }

    /** @return HasMany<FilterCastModel, $this> */
    public function appendices(): HasMany
    {
        return $this->hasMany(FilterCastModel::class);
    }
}

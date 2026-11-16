<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\CourierData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model that hands itself out as a Data object, the way an application writes `toDTO()`: which of its
 * columns a response link reads off the Data body. Only ever reflected — never queried.
 *
 * @property int $id The courier identifier.
 * @property string $code The courier's public code.
 * @property string $name The courier's display name.
 * @property string|null $depot_id The depot the courier works from.
 */
class Courier extends Model
{
    /**
     * The depot the courier works from.
     *
     * @return BelongsTo<Depot, $this>
     */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function toDTO(): CourierData
    {
        return new CourierData(
            id: $this->id,
            code: $this->code,
            name: strtoupper($this->name),
        );
    }
}

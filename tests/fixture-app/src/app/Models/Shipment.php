<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The real-engine foreign-key proof's subject model: `depot_id` carries NO cast and is not the key,
 * so the only way a filter on it gets typed is the `depot()` relation's hop onto {@see Depot}'s uuid
 * key. Only ever reflected — never queried.
 *
 * @property int $id          The shipment identifier.
 * @property string $depot_id The owning depot's uuid.
 * @property string $status   Free-form dispatch status.
 */
class Shipment extends Model
{
    /**
     * The owning depot.
     *
     * @return BelongsTo<Depot, $this>
     */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }
}

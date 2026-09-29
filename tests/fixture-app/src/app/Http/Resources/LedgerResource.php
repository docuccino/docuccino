<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource whose `::collection()` builds its named collection.
 *
 * @mixin User
 */
class LedgerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id];
    }

    /**
     * @param  mixed  $resource
     */
    protected static function newCollection($resource): LedgerCollection
    {
        return new LedgerCollection($resource);
    }
}

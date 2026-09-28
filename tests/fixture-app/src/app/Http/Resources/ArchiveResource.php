<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource building its own generic collection, typed of itself.
 *
 * @mixin User
 */
class ArchiveResource extends JsonResource
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
     * @return ArchiveCollection<static>
     */
    protected static function newCollection($resource): ArchiveCollection
    {
        return new ArchiveCollection($resource, static::class);
    }
}

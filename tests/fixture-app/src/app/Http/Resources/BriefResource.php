<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource handing every `::collection()` of it to {@see BriefCollection}.
 *
 * @mixin User
 */
class BriefResource extends JsonResource
{
    /**
     * @param  mixed  $resource
     */
    protected static function newCollection($resource): BriefCollection
    {
        return new BriefCollection($resource, static::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['name' => $this->name];
    }
}

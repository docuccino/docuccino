<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A base resource handing every `::collection()` of its family to {@see ListedCollection}, which is how
 * Laravel lets a family share one collection class: `collection()` calls `static::newCollection()`.
 */
abstract class ListedResource extends JsonResource
{
    /**
     * @param  mixed  $resource
     */
    protected static function newCollection($resource): ListedCollection
    {
        return new ListedCollection($resource, static::class);
    }
}

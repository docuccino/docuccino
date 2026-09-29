<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractPaginator;

/**
 * A resource whose untyped `newCollection()` builds one of two collections, depending on what it is handed.
 *
 * @mixin User
 */
class SketchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id];
    }

    protected static function newCollection($resource)
    {
        if ($resource instanceof AbstractPaginator) {
            return new ListedCollection($resource, static::class);
        }

        return new AnonymousResourceCollection($resource, static::class);
    }
}

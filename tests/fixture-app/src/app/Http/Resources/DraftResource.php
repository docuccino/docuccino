<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource overriding `newCollection()` in the framework's own style, with no return type: the analyser
 * reads the framework's docblock through the override, and only the body names the class it builds.
 *
 * @mixin User
 */
class DraftResource extends JsonResource
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
        return new ListedCollection($resource, static::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource whose `with()` adds a member on one branch and nothing on the other.
 *
 * @mixin User
 */
class TracedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        if (! $request->boolean('trace')) {
            return [];
        }

        return ['trace' => ['queries' => 0]];
    }
}

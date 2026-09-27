<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource with a ternary `toArray` — one return site, two shapes — and a `with()` whose second
 * branch hands back request input, which has no shape to read.
 *
 * @mixin User
 */
class CompactableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $request->boolean('compact')
            ? ['id' => $this->id]
            : ['id' => $this->id, 'name' => $this->name];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        if ($request->has('debug')) {
            return ['debug' => ['queries' => 0]];
        }

        return $request->only(['trace']);
    }
}

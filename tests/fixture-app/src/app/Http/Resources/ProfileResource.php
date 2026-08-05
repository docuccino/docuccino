<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource with request-dependent branches (two return sites — Wave C item 6) and a nested object
 * carrying a `when(...)` conditional (item 7). Idiomatic: a magic-attribute model via `@mixin`, real
 * conditional helpers — the real engine types the two sites as distinct array shapes and the nested
 * `role` as `string|MissingValue` (the ConditionallyLoadsAttributes stub).
 *
 * @mixin User
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($request->boolean('minimal')) {
            return [
                'id' => $this->id,
                'name' => $this->name,
            ];
        }

        return [
            'id' => $this->id,
            'email' => $this->email,
            'meta' => [
                'name' => $this->name,
                'role' => $this->when($request->boolean('with_role'), 'member'),
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Conditional fields: with the ConditionallyLoadsAttributes stub the real engine types
            // these as `T|MissingValue`, so they recover as optional + typed rather than collapsing
            // to `mixed` (audit api-resources #1). `role` uses the value form; `badge` the
            // whenLoaded closure form.
            'role' => $this->when($request->boolean('with_role'), 'member'),
            'badge' => $this->whenLoaded('roles', fn (): string => 'gold'),
        ];
    }
}

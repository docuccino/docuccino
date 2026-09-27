<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stamps a header on the framework's own rendering: still the resource, under the status it decides.
 *
 * @mixin User
 */
class HeaderedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id];
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        return parent::toResponse($request)->header('X-Resource', 'user');
    }
}

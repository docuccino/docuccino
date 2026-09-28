<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Always answers 202 with the resource the framework renders.
 *
 * @mixin User
 */
class AcceptedResource extends JsonResource
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
        return parent::toResponse($request)->setStatusCode(202);
    }
}

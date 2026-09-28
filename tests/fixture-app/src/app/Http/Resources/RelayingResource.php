<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Relays a body it was handed on one branch, which nothing can read: the resource branch alone is not the
 * whole of what this sends.
 *
 * @mixin User
 */
class RelayingResource extends JsonResource
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
        if ($request->has('relay')) {
            return JsonResponse::fromJsonString((string) $request->input('relay'));
        }

        return parent::toResponse($request);
    }
}

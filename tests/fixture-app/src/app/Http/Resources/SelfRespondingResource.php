<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource that writes its own response instead of letting the framework render it: whatever reaches
 * `toResponse()` — directly, through `response()`, or by returning the resource bare — is this body under
 * this status, never the resource envelope.
 *
 * @mixin User
 */
class SelfRespondingResource extends JsonResource
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
        return new JsonResponse(['queued' => true], 202);
    }
}

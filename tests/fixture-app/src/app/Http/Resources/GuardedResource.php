<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Answers with an error of its own on one branch and hands every other request back to the framework:
 * two responses, the 410 and the resource envelope.
 *
 * @mixin User
 */
class GuardedResource extends JsonResource
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
        if ($this->email_verified_at === null) {
            return response()->json(['message' => 'This account is no longer available.'], 410);
        }

        return parent::toResponse($request);
    }
}

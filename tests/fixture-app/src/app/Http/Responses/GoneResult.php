<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

/** A result that renders itself as a fixed 410 through the response factory. */
class GoneResult implements Responsable
{
    public function toResponse($request): JsonResponse
    {
        return response()->json(['message' => 'This resource is gone.'], 410);
    }
}

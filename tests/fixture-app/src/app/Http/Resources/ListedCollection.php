<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The collection a resource family shares through {@see ListedResource::newCollection()}: a list that
 * always carries a `meta` member and the API version beside its `data`.
 */
class ListedCollection extends AnonymousResourceCollection
{
    public function with(Request $request): array
    {
        return [
            'meta' => ['listed_at' => now()->toIso8601String()],
            'api_version' => '2',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A base resource adding top-level members to every response through `with()`, written the way an
 * application writes one: a `meta` object assembled by an overridable hook, beside a constant.
 */
abstract class EnvelopedResource extends JsonResource
{
    /**
     * @return array{meta: object, api_version: string}
     */
    public function with(Request $request): array
    {
        return [
            'meta' => (object) $this->meta($request),
            'api_version' => '2',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function meta(Request $request): array
    {
        return [];
    }
}

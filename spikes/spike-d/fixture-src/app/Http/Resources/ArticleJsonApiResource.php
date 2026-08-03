<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use TiMacDonald\JsonApi\JsonApiResource;

/**
 * A real timacdonald/json-api resource for the fixture app. The real engine analyses toAttributes()
 * into a constant array shape ({title: string, body: string}); the JSON:API integration's shared
 * document builder consumes that shape. Exercises the timacdonald recovery half against the ACTUAL
 * engine (not a stub) — spike-d / Phase 5c M2.
 */
final class ArticleJsonApiResource extends JsonApiResource
{
    public string $type = 'articles';

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'title' => (string) $this->resource->title,
            'body' => (string) $this->resource->body,
        ];
    }
}

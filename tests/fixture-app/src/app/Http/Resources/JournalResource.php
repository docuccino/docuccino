<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource overriding `newCollection()` with no return type to build its named collection, which is no
 * anonymous collection at all.
 *
 * @mixin User
 */
class JournalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id];
    }

    protected static function newCollection($resource)
    {
        return new JournalCollection($resource);
    }
}

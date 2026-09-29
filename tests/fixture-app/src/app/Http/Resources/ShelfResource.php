<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * A {@see ListedResource} whose `collection()` is also narrowed with a `@method static` tag, the way an
 * application tells its analyser what the override returns. The tag names a real inherited method, so
 * PHP calls that method, never `__callStatic()`; `featured()` names none, so PHP forwards it.
 *
 * @method static ListedCollection collection(mixed $resource)
 * @method static ListedCollection featured(mixed $resource) a macro, registered at boot
 *
 * @mixin User
 */
class ShelfResource extends ListedResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
        ];
    }
}

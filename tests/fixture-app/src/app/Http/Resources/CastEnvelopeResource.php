<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource building its objects inline with `(object)` casts: an empty `settings` in its body, and in
 * `with()` an empty `meta`, a keyed `links`, a cast nested in a cast, and one whose keys depend on the request.
 *
 * @mixin User
 */
class CastEnvelopeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'settings' => (object) []];
    }

    /**
     * @return array{meta: object, links: object, paging: object, filters: object}
     */
    public function with(Request $request): array
    {
        $filters = ['sort' => 'name'];
        if ($request->has('q')) {
            $filters['q'] = $request->string('q')->value();
        }

        return [
            'meta' => (object) [],
            'links' => (object) ['self' => $request->url()],
            'paging' => (object) ['cursor' => (object) [], 'pages' => [1, 2]],
            'filters' => (object) $filters,
        ];
    }
}

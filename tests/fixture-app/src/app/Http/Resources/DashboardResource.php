<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource using the `merge`/`mergeWhen` family idiomatically (Wave C item 5). With the merge stub
 * the real engine types the values as `MergeValue<array{…}>`, so `ToArrayObject` splices their keys
 * into the parent: the unconditional `merge` keys are required, the `mergeWhen` keys optional.
 *
 * @mixin User
 */
class DashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            $this->merge([
                'name' => $this->name,
                'email' => $this->email,
            ]),
            $this->mergeWhen($request->boolean('detailed'), [
                'role' => 'member',
            ]),
        ];
    }
}

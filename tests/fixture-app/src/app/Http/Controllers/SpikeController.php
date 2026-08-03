<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpikeController extends Controller
{
    /**
     * Pass criterion (a): Larastan generics must flow so the return type is
     * Collection<int, App\Models\User>, not a bare Collection.
     *
     * @return Collection<int, User>
     */
    public function listUsers(): Collection
    {
        return User::all();
    }

    /**
     * Pass criterion (b): out of the box response()->json([...]) is a bare
     * JsonResponse and the payload shape is lost. With our stub extension +
     * DynamicMethodReturnTypeExtension the return type should carry the
     * constant array shape array{id: 1, name: 'x', tags: array{'a', 'b'}}.
     */
    public function jsonShape(): JsonResponse
    {
        return response()->json([
            'id' => 1,
            'name' => 'x',
            'tags' => ['a', 'b'],
        ]);
    }

    /**
     * Pass criterion (c): a resource collection should infer as
     * AnonymousResourceCollection (bonus: carrying UserResource).
     */
    public function resourceCollection(): AnonymousResourceCollection
    {
        return UserResource::collection(User::all());
    }

    /**
     * Pass criterion (d): union across return paths. Each return statement
     * should be reported with its own distinct type and line number.
     */
    public function unionAction(bool $asJson): JsonResponse|UserResource
    {
        if ($asJson) {
            return response()->json([
                'ok' => true,
            ]);
        }

        return new UserResource(User::query()->firstOrFail());
    }
}

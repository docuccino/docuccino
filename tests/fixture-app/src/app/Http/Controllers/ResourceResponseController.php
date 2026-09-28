<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AcceptedResource;
use App\Http\Resources\GuardedResource;
use App\Http\Resources\HeaderedResource;
use App\Http\Resources\InheritingRespondingResource;
use App\Http\Resources\ReleaseResource;
use App\Http\Resources\RelayingResource;
use App\Http\Resources\SelfRespondingResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A resource handed back as the response the framework renders from it — `->response()`, the status idiom
 * `->response()->setStatusCode(201)`, `->toResponse($request)` — beside the bare return each is equivalent
 * to. The framework's own `toResponse()` sends the same body either way, so all of them document one.
 */
class ResourceResponseController extends Controller
{
    public function plain(string $id): UserResource
    {
        return new UserResource(User::query()->findOrFail($id));
    }

    public function response(string $id): JsonResponse
    {
        return (new UserResource(User::query()->findOrFail($id)))->response();
    }

    public function created(string $id): JsonResponse
    {
        return (new UserResource(User::query()->findOrFail($id)))->response()->setStatusCode(201);
    }

    public function toResponse(Request $request, string $id): JsonResponse
    {
        return (new UserResource(User::query()->findOrFail($id)))->toResponse($request);
    }

    public function headed(string $id): JsonResponse
    {
        return (new UserResource(User::query()->findOrFail($id)))->response()->header('X-Value', 'True');
    }

    public function store(Request $request): JsonResponse
    {
        return (new UserResource(User::create($request->only('name', 'email'))))->response();
    }

    public function named(Request $request): JsonResponse
    {
        $response = UserResource::make(User::query()->firstOrFail())->response($request);
        $response->header('X-Value', 'True');

        return $response;
    }

    public function collection(): JsonResponse
    {
        return UserResource::collection(User::all())->response();
    }

    public function paginated(): JsonResponse
    {
        return UserResource::collection(User::query()->paginate())->response();
    }

    public function enveloped(string $id): JsonResponse
    {
        return (new ReleaseResource(User::query()->findOrFail($id)))->additional(['meta' => ['v' => 1]])->response();
    }

    public function overriddenResponse(string $id): JsonResponse
    {
        return (new SelfRespondingResource(User::query()->findOrFail($id)))->response();
    }

    public function overriddenToResponse(Request $request, string $id): JsonResponse
    {
        return (new SelfRespondingResource(User::query()->findOrFail($id)))->toResponse($request);
    }

    public function overriddenBare(string $id): SelfRespondingResource
    {
        return new SelfRespondingResource(User::query()->findOrFail($id));
    }

    public function guarded(string $id): GuardedResource
    {
        return new GuardedResource(User::query()->findOrFail($id));
    }

    public function guardedResponse(string $id): JsonResponse
    {
        return (new GuardedResource(User::query()->findOrFail($id)))->response();
    }

    public function headeredOverride(string $id): HeaderedResource
    {
        return new HeaderedResource(User::query()->findOrFail($id));
    }

    public function acceptedOverride(string $id): AcceptedResource
    {
        return new AcceptedResource(User::query()->findOrFail($id));
    }

    public function inherited(string $id): InheritingRespondingResource
    {
        return new InheritingRespondingResource(User::query()->findOrFail($id));
    }

    public function relabelled(string $id): JsonResponse
    {
        return (new UserResource(User::query()->findOrFail($id)))->response()->header('Content-Type', 'application/vnd.api+json');
    }

    public function relaying(string $id): RelayingResource
    {
        return new RelayingResource(User::query()->findOrFail($id));
    }
}

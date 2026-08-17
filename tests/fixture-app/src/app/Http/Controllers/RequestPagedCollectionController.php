<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ListPageSize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A resource collection sized by the request, with no Query Builder anywhere: the shared paginating-terminal
 * detector has to follow the size argument into the clamp helper for itself, or the resource producer and
 * the Query-Builder producer would name different keys for the same shape.
 */
class RequestPagedCollectionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = ListPageSize::clamp($request);

        return UserResource::collection(User::query()->paginate($perPage));
    }
}

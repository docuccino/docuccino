<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Paginated resource-collection endpoints (Wave C item 1). The static return type is the same
 * `AnonymousResourceCollection<UserResource>` for every mode, so the paginator kind is recoverable
 * only by tracing the paginating terminal — which is exactly what PaginationTerminalVisitor does.
 */
class UserPageController extends Controller
{
    public function lengthAware(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->paginate(15));
    }

    public function simple(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->simplePaginate(15));
    }

    public function cursor(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->cursorPaginate(15));
    }
}

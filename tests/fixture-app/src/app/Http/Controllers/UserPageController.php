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

    /**
     * The same page of users under an application's own key — `?p=2`, not `?page=2`. Only the third
     * argument says so, so a documented `page` here would name a key this endpoint never reads.
     */
    public function renamedKey(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->paginate(15, ['*'], 'p'));
    }
}

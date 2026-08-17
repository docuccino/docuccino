<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Queries\UserIndexQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * A list endpoint whose page size comes off the REQUEST rather than a call-site literal: the action hands
 * the request to a custom terminal, which hands it to a clamp helper on another class, which reads
 * `per_page`. Three frames, no literal anywhere, and no annotation — the trace has to follow the request
 * into the callee to know the key is part of this endpoint's contract.
 */
class RequestPagedListController extends Controller
{
    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function index(Request $request): LengthAwarePaginator
    {
        return (new UserIndexQuery())->query()->paginateRequested($request);
    }
}

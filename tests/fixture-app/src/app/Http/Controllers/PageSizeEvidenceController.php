<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ListPageSize;
use App\Support\PresetPageSize;
use App\Support\TeamPageSize;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Four list endpoints whose page size comes from a helper, differing only in whether the request key that
 * helper reads is the size it answers with. Two of them page by a key and two page by a literal, and
 * nothing at any call site says which — only what each helper's returned value is built from.
 */
class PageSizeEvidenceController extends Controller
{
    /**
     * The shared clamp, imported from a trait: the read is the value, so `per_page` is this endpoint's.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function clampedByTrait(Request $request): LengthAwarePaginator
    {
        return User::query()->paginate(TeamPageSize::pageSize($request));
    }

    /**
     * The summary's size is fixed. The request-clamped size caps a different query, and the helper that
     * reads it is imported from a trait whose lines overlap this file's own.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function fixedSummary(Request $request): LengthAwarePaginator
    {
        $cap = TeamPageSize::pageSize($request);

        return User::query()->limit($cap)->paginate(TeamPageSize::summarySize());
    }

    /**
     * The clamp with the read named in a local first, under a key of its own.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function limited(Request $request): LengthAwarePaginator
    {
        return User::query()->paginate(ListPageSize::limit($request));
    }

    /**
     * A preset selector and an ordering key: both are read, neither is a size.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function byPreset(Request $request): LengthAwarePaginator
    {
        return User::query()->paginate(PresetPageSize::forPreset($request));
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function recentFirst(Request $request): LengthAwarePaginator
    {
        return User::query()->paginate(PresetPageSize::whenRecent($request));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A controller that paginates through spatie/laravel-json-api-paginate's `jsonPaginate()` macro, built
 * inside a helper (so the terminal is reached one call deep) on a builder that has already been
 * narrowed by `->where()`. Exercises JsonApiPaginateTraceVisitor's terminal recognition, its
 * builder-receiver matching, and its literal argument folding against the REAL engine — spike-d /
 * Phase 5c M2.
 */
final class JsonApiPaginateController
{
    public function index(): LengthAwarePaginator
    {
        return $this->paginated();
    }

    private function paginated(): LengthAwarePaginator
    {
        // jsonPaginate($maxResults, $defaultSize): the two literals must fold from this call site.
        return User::query()->where('name', '!=', '')->jsonPaginate(100, 25);
    }
}

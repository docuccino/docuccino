<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Write endpoints for the created-model 201 recovery (Wave C item 4). `store` wraps a fresh
 * `User::create(...)` (wasRecentlyCreated → 201); `show` wraps an existing model (a plain 200) — the
 * negative case proving the visitor does not over-recover.
 */
class UserWriteController extends Controller
{
    public function store(Request $request): UserResource
    {
        return new UserResource(User::create($request->only('name', 'email')));
    }

    public function show(string $id): UserResource
    {
        return new UserResource(User::query()->findOrFail($id));
    }
}

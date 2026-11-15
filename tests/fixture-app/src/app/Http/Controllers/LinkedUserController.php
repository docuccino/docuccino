<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\LinkedUserResource;
use App\Models\User;

/**
 * Builds one resource around a model in each of the ways an action does, and around something that is
 * not exactly one model.
 */
class LinkedUserController
{
    public function made(): LinkedUserResource
    {
        return new LinkedUserResource(User::create([]));
    }

    public function shown(User $user): LinkedUserResource
    {
        return LinkedUserResource::make($user);
    }

    public function helped(): LinkedUserResource
    {
        return $this->wrap(User::query()->firstOrFail());
    }

    public function maybe(): LinkedUserResource
    {
        return new LinkedUserResource(User::query()->find(1));
    }

    private function wrap(User $user): LinkedUserResource
    {
        return new LinkedUserResource($user);
    }
}

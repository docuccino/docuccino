<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\LinkedUserResource;
use App\Http\Resources\MixinUserResource;
use App\Models\Product;
use App\Models\User;

/**
 * Builds one resource around a model in each of the ways an action does, and around something that is
 * not exactly one model; and one whose `@mixin` names a model, built around it, around either of two,
 * and handed out by the model itself where no construction shows.
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

    public function agreed(): MixinUserResource
    {
        return new MixinUserResource(User::create([]));
    }

    public function either(bool $flag): MixinUserResource
    {
        return new MixinUserResource($flag ? User::query()->firstOrFail() : Product::query()->firstOrFail());
    }

    public function handedOut(User $user): MixinUserResource
    {
        return $user->toResource(MixinUserResource::class);
    }

    private function wrap(User $user): LinkedUserResource
    {
        return new LinkedUserResource($user);
    }
}

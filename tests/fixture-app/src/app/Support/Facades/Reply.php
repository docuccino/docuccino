<?php

declare(strict_types=1);

namespace App\Support\Facades;

use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Support\Facades\Facade;

/**
 * The application's own facade over the response factory, aliased in the root namespace as `\Reply` by
 * {@see AppServiceProvider}, as an application adds an alias of its own.
 *
 * @method static \Illuminate\Http\JsonResponse json(mixed $data = [], int $status = 200, array $headers = [], int $options = 0)
 */
class Reply extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ResponseFactory::class;
    }
}

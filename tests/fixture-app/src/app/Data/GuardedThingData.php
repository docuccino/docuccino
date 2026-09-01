<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\Data;

/**
 * The same "created here, ok everywhere else" decision written as a guard clause rather than a ternary,
 * which is the other way applications spell it. Two returns, so the route settles nothing and both
 * statuses stay documented on every operation. Only ever analysed.
 */
class GuardedThingData extends Data
{
    public function __construct(
        public string $id,
    ) {}

    protected function calculateResponseStatus(Request $request): int
    {
        if ($request->routeIs('*things.store')) {
            return Response::HTTP_CREATED;
        }

        return Response::HTTP_OK;
    }
}

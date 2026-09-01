<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\Data;

/**
 * A spatie Data class whose calculateResponseStatus() override picks between two class constants on the
 * route's NAME — the idiomatic way to say "created here, ok everywhere else" for a DTO several endpoints
 * return. The engine folds the override's return type to `200|201`, and the route settles which of the
 * two an operation publishes. Only ever analysed.
 */
class ConditionalThingData extends Data
{
    public function __construct(
        public string $id,
    ) {}

    protected function calculateResponseStatus(Request $request): int
    {
        return $request->routeIs('*things.store')
            ? Response::HTTP_CREATED
            : Response::HTTP_OK;
    }
}

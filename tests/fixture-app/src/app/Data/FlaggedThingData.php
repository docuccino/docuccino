<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\Data;

/**
 * A feature flag left switched off above the same route-name ternary, so the `return` under it is code
 * the analyser proves unreachable and a plain parse does not. Only ever analysed.
 */
class FlaggedThingData extends Data
{
    /** Off, so the status below it is one no instance of this class can answer with. */
    private const ALWAYS_ACCEPTED = false;

    public function __construct(
        public string $id,
    ) {}

    protected function calculateResponseStatus(Request $request): int
    {
        if (self::ALWAYS_ACCEPTED) {
            return Response::HTTP_ACCEPTED;
        }

        return $request->routeIs('*things.store')
            ? Response::HTTP_CREATED
            : Response::HTTP_OK;
    }
}

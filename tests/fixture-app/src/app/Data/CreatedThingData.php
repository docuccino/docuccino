<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\Data;

/**
 * A spatie Data class overriding calculateResponseStatus() to a class constant (Response::HTTP_CREATED — Eos's exact
 * shape), so the real engine folds the class-const fetch to a literal-int return type — the recovery half the
 * DataResponseStatus fold (gap 5) reads. Only ever analysed.
 */
class CreatedThingData extends Data
{
    public function __construct(
        public string $id,
    ) {}

    protected function calculateResponseStatus(Request $request): int
    {
        return Response::HTTP_CREATED;
    }
}

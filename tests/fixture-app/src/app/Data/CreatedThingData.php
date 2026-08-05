<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/**
 * A spatie Data class overriding calculateResponseStatus() to a constant 201, so the real engine's
 * return-type inference over the override yields a literal-int type — the recovery half the
 * DataResponseStatus fold (gap 5) reads. Only ever analysed.
 */
class CreatedThingData extends Data
{
    public function __construct(
        public string $id,
    ) {}

    protected function calculateResponseStatus(Request $request): int
    {
        return 201;
    }
}

<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\Concerns\WritesProblemResponse;
use Spatie\LaravelData\Data;

/**
 * {@see OwnResponseProblemData} with the response moved to a trait — the second problem payload, at which
 * point an app stops copying the method and shares it. The code that runs is the same code, so the
 * documented status, media type and payload owed here are the same too. Only ever analysed.
 */
class TraitResponseProblemData extends Data
{
    use WritesProblemResponse;

    public function __construct(
        public string $type,
        public int $status,
    ) {}
}

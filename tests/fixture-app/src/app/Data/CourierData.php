<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Courier;
use Spatie\LaravelData\Data;

/**
 * A courier as a response sends it, built from {@see Courier} in each of the ways an action
 * builds one.
 */
final class CourierData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ListingStatus;
use Spatie\LaravelData\Data;

/**
 * One form zone inside a {@see SnapshotData}. Its `status` is a NATIVELY typed backed enum, which is the
 * working half of the enum contrast: reflection hands the engine an enum type directly, where a column
 * documented only by a `@property` docblock (App\Models\Listing::$status) goes through the type-string
 * grammar instead. Only ever reflected.
 */
final class SnapshotFormData extends Data
{
    public function __construct(
        /**
         * UUID of the form materialised into the zone.
         *
         * @example 0192f1a2-b3c4-7000-8000-000000000001
         */
        public readonly string $form_id,

        /**
         * Zone key from the pinned blueprint version.
         *
         * @example candidate-profile
         */
        public readonly string $zone_key,

        /**
         * Publication status frozen at submit.
         *
         * @example open
         */
        public readonly ListingStatus $status,
    ) {}
}

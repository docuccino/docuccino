<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * A challenge body carrying a spatie `DataCollection` whose item class is named ONLY by the constructor
 * `@param` generic — the form a Data class takes when the collection is built through `::collect()` and
 * no `#[DataCollectionOf]` is written. Only ever reflected.
 */
final class MfaChallengeData extends Data
{
    /**
     * @param  DataCollection<int, SnapshotFormData>  $mfa_factors
     */
    public function __construct(
        /**
         * Token to include when completing the challenge.
         *
         * @example pat_01H9XYZABC123DEF456GHI789
         */
        public readonly string $pending_authentication_token,

        /** The factors the user can complete the challenge with. */
        public readonly DataCollection $mfa_factors,
    ) {}
}

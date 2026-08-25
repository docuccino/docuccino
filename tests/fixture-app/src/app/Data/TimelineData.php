<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * A Data class whose timestamps are NULLABLE — the everyday shape of a published-at / expires-at column,
 * and the one the fixture app had no example of. `expiresAt` pairs that nullability with the `format: 'U'`
 * cast, so the integer wire type has to keep the null too; `createdAt` is the non-null control. Only ever
 * analysed, never dispatched.
 *
 * `@example null` on `publishedAt` is the authored form that only reads as null if the published schema
 * admits null — beside a bare `string` it publishes the four characters `null` instead.
 */
final class TimelineData extends Data
{
    public function __construct(
        /**
         * When the listing went live, or null while it is still a draft.
         *
         * @example null
         */
        public readonly ?CarbonImmutable $publishedAt,

        /** When the listing stops accepting applications, or null if it never does. */
        #[WithCast(DateTimeInterfaceCast::class, format: 'U')]
        public readonly ?CarbonImmutable $expiresAt,

        /** When the listing was first created. */
        public readonly CarbonImmutable $createdAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Attributes\Hidden as DocuccinoHidden;
use Spatie\LaravelData\Attributes\FromRouteParameter;
use Spatie\LaravelData\Attributes\Hidden as SpatieHidden;
use Spatie\LaravelData\Data;

/**
 * Idiomatic request-DTO exclusion surfaces: a plain body field, a `#[FromRouteParameter]` property
 * (populated from the route binding, not the body), a Docuccino `#[Hidden]` and a spatie `#[Hidden]`
 * property (hidden from the documented contract in both directions). Only ever reflected.
 */
final class RequestExclusionData extends Data
{
    public function __construct(
        public string $name,
        #[FromRouteParameter('id')]
        public string $id,
        #[DocuccinoHidden]
        public string $internalToken,
        #[SpatieHidden]
        public string $secret,
    ) {}
}

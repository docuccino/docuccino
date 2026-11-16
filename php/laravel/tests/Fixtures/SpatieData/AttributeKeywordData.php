<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\GreaterThan;
use Spatie\LaravelData\Attributes\Validation\GreaterThanOrEqualTo;
use Spatie\LaravelData\Attributes\Validation\IPv4;
use Spatie\LaravelData\Attributes\Validation\IPv6;
use Spatie\LaravelData\Attributes\Validation\LessThan;
use Spatie\LaravelData\Attributes\Validation\LessThanOrEqualTo;
use Spatie\LaravelData\Attributes\Validation\ListType;
use Spatie\LaravelData\Attributes\Validation\MimeTypes;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Validation attributes whose rule is not their class name snake-cased (`#[GreaterThan]` is `gt`), and one
 * whose arguments are named out of order.
 */
final class AttributeKeywordData extends Data
{
    public function __construct(
        #[ListType]
        public array|Optional $items,
        #[GreaterThan(5)]
        public int|Optional $above,
        #[GreaterThanOrEqualTo(5)]
        public int|Optional $atLeast,
        #[LessThan(10)]
        public int|Optional $below,
        #[LessThanOrEqualTo(10)]
        public int|Optional $atMost,
        #[IPv4]
        public string|Optional $v4,
        #[IPv6]
        public string|Optional $v6,
        #[Between(max: 10, min: 1)]
        public int|Optional $count,
        #[MimeTypes('image/png')]
        public UploadedFile|Optional $avatar,
    ) {}
}

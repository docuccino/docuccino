<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** One author, as a page of them lists it. */
final class AuthorData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}

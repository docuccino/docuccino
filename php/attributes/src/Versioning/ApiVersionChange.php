<?php

declare(strict_types=1);

namespace Docuccino\Attributes\Versioning;

use Attribute;

/**
 * Declares one API version change: what the API did BEFORE `$since`, and the sentence a consumer
 * reads about it. The code is always the newest version, so a change is applied in REVERSE to derive
 * an older version's document — it never describes anything the current code still does.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ApiVersionChange
{
    /**
     * @param  string  $since  the API version this change shipped in, e.g. `2026-09-01`
     * @param  string  $description  one sentence, written for the consumer who has to migrate
     */
    public function __construct(
        public string $since,
        public string $description,
    ) {}
}

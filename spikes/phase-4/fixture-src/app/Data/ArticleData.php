<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * A spatie Data class the docuccino real-engine integration test analyses via
 * classMetadata(): its typed public promoted properties let the engine recover
 * precise property types through reflection (not a stub), covering the
 * type-recovery half of the Spatie Data integration.
 */
class ArticleData extends Data
{
    public function __construct(
        public int $id,
        public string $title,
        public ?string $subtitle,
    ) {}
}
